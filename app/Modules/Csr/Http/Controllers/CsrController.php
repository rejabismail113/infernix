<?php

declare(strict_types=1);

namespace App\Modules\Csr\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ActivityDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\Contracts\HtmlToPdf;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Secrets\SecretRedactor;
use App\Modules\Administration\Models\Company;
use App\Modules\Csr\Models\CsrEvidence;
use App\Modules\Csr\Models\CsrInKindContribution;
use App\Modules\Csr\Models\CsrProgramme;
use App\Modules\Csr\Models\CsrVolunteer;
use App\Modules\Csr\Reports\CsrReports;
use App\Modules\Csr\Services\CsrService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CSR programmes (M08): programme register, volunteers, in-kind (separate from cash), restricted evidence photos
 * with consent, cash spend via the programme activity, CSR annual report PDF.
 *
 * @see SRS M08-01, M08-02, M08-03, M08-04
 */
final class CsrController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly CsrService $service) {}

    public function index(Request $request, PermissionService $permissions, StaffDirectory $staff): Response
    {
        $this->authorize('viewAny', CsrProgramme::class);
        $user = $this->user($request);
        $scope = $permissions->scopeFor($user, 'csr_programmes', PermissionAction::View);
        $staffId = $staff->idForUser($user);
        $rows = CsrProgramme::query()
            ->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhereHas('volunteers', fn ($v) => $v->where('staff_profile_id', $staffId ?? 0))))
            ->orderByDesc('programme_date')->limit(300)->get();

        return Inertia::render('Csr/Index', [
            'programmes' => $rows->map(fn (CsrProgramme $p): array => $this->row($p) + $this->service->summary($p))->values(),
            'can' => ['create' => $user->can('create', CsrProgramme::class), 'export' => $user->can('export', CsrProgramme::class)],
            'year' => (int) today()->format('Y'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CsrProgramme::class);
        $data = $request->validate($this->rules() + ['budget' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => redirect()->route('csr.programmes.show', $this->service->create($data, $this->user($request))->id), __('Programme created.'));
    }

    public function show(Request $request, CsrProgramme $programme, DocumentStore $documents, ActivityDirectory $activities, StaffDirectory $staff): Response
    {
        $this->authorize('view', $programme);
        $user = $this->user($request);
        $programme->load('volunteers', 'inKind', 'evidence');
        $canEdit = $user->can('update', $programme);
        $seeEvidence = $user->can('viewEvidence', $programme);
        $docs = $seeEvidence ? collect($documents->linkedTo('csr_programme', $programme->id))->keyBy('document_id') : collect();

        return Inertia::render('Csr/Show', [
            'programme' => $this->row($programme) + ['objectives' => $programme->objectives, 'sponsor' => $programme->sponsor, 'outcome' => $programme->outcome, 'target_count' => $programme->target_count, 'actual_count' => $programme->actual_count],
            'activity' => $programme->activity_id !== null ? $activities->snapshot($programme->activity_id) : null,
            'summary' => $this->service->summary($programme),
            'volunteers' => $programme->volunteers->map(fn (CsrVolunteer $v): array => ['staff_profile_id' => $v->staff_profile_id, 'name' => $v->name, 'hours' => $v->hours, 'role' => $v->role])->values(),
            'inKind' => $programme->inKind->map(fn (CsrInKindContribution $c): array => ['id' => $c->id, 'item' => $c->item, 'quantity' => $c->quantity, 'estimated_value' => (string) $c->estimated_value->getAmount(), 'source' => $c->source, 'valuation_basis' => $c->valuation_basis])->values(),
            'evidence' => $seeEvidence ? $programme->evidence->map(fn (CsrEvidence $e): array => ['id' => $e->id, 'document_id' => $e->document_id, 'title' => $docs[$e->document_id]['file_name'] ?? $docs[$e->document_id]['title'] ?? 'photo',
                'people_identifiable' => $e->people_identifiable, 'consent_obtained' => $e->consent_obtained, 'consent_note' => $e->consent_note, 'publishable' => $e->publishable])->values() : null,
            'staff' => $canEdit ? $staff->options($user) : [],
            'can' => ['edit' => $canEdit, 'evidence' => $user->can('manageEvidence', $programme), 'see_evidence' => $seeEvidence],
        ]);
    }

    public function update(Request $request, CsrProgramme $programme): RedirectResponse
    {
        $this->authorize('update', $programme);
        $data = $request->validate($this->rules() + ['status' => ['required', 'in:planned,ongoing,completed,cancelled'], 'actual_count' => ['nullable', 'integer', 'min:0'], 'outcome' => ['nullable', 'string', 'max:10000']]);

        return $this->attempt(fn () => $this->service->update($programme, $data, $this->user($request)), __('Saved.'));
    }

    public function volunteers(Request $request, CsrProgramme $programme): RedirectResponse
    {
        $this->authorize('update', $programme);
        $data = $request->validate(['volunteers' => ['present', 'array', 'max:300'], 'volunteers.*.staff_profile_id' => ['nullable', 'integer'], 'volunteers.*.name' => ['nullable', 'string', 'max:255'],
            'volunteers.*.hours' => ['nullable', 'regex:/^\d{1,5}(\.\d{1,2})?$/'], 'volunteers.*.role' => ['nullable', 'string', 'max:60']]);

        return $this->attempt(fn () => $this->service->saveVolunteers($programme, $data['volunteers'], $this->user($request)), __('Volunteers saved.'));
    }

    public function addInKind(Request $request, CsrProgramme $programme): RedirectResponse
    {
        $this->authorize('update', $programme);
        $data = $request->validate(['item' => ['required', 'string', 'max:255'], 'quantity' => ['nullable', 'regex:/^\d{1,8}(\.\d{1,2})?$/'], 'estimated_value' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'source' => ['required', 'string', 'max:255'], 'valuation_basis' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->service->addInKind($programme, $data, $this->user($request)), __('In-kind contribution recorded (not cash).'));
    }

    public function removeInKind(Request $request, CsrInKindContribution $contribution): RedirectResponse
    {
        $this->authorize('update', CsrProgramme::query()->findOrFail($contribution->programme_id));

        return $this->attempt(fn () => $this->service->removeInKind($contribution, $this->user($request)), __('Removed.'));
    }

    public function addEvidence(Request $request, CsrProgramme $programme): RedirectResponse
    {
        $this->authorize('manageEvidence', $programme);
        $data = $request->validate(['file' => ['required', 'file', 'max:20480'], 'people_identifiable' => ['required', 'boolean'], 'consent_obtained' => ['required', 'boolean'], 'consent_note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->addEvidence($programme, $request->file('file'), (bool) $data['people_identifiable'], (bool) $data['consent_obtained'], $data['consent_note'] ?? null, $this->user($request)), __('Photo stored as restricted evidence.'));
    }

    public function consent(Request $request, CsrEvidence $evidence): RedirectResponse
    {
        $this->authorize('manageEvidence', CsrProgramme::query()->findOrFail($evidence->programme_id));
        $data = $request->validate(['consent_note' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->recordConsent($evidence, $data['consent_note'], $this->user($request)), __('Consent recorded.'));
    }

    public function publishable(Request $request, CsrEvidence $evidence): RedirectResponse
    {
        $this->authorize('manageEvidence', CsrProgramme::query()->findOrFail($evidence->programme_id));
        $data = $request->validate(['publishable' => ['required', 'boolean']]);

        return $this->attempt(fn () => $this->service->setPublishable($evidence, (bool) $data['publishable'], $this->user($request)), __('Saved.'));
    }

    /** CSR annual report PDF (audited export, redacted). */
    public function annualReport(Request $request, CsrReports $reports, HtmlToPdf $pdf, SecretRedactor $redactor): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('export', CsrProgramme::class);
        $year = (int) $request->validate(['year' => ['required', 'integer', 'min:2020', 'max:2100']])['year'];
        $html = view('csr.annual-report', ['report' => $reports->annual($year), 'company' => Company::current()->name])->render();
        $bytes = $pdf->render($redactor->scrub($html));
        Audit::export('csr.annual-report', ['year' => $year], $this->user($request));

        return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"csr-annual-report-{$year}.pdf\"", 'Cache-Control' => 'no-store']);
    }

    /** @return array<string, mixed> */
    private function row(CsrProgramme $p): array
    {
        return ['id' => $p->id, 'programme_no' => $p->programme_no, 'title' => $p->title, 'programme_date' => $p->programme_date->toDateString(), 'partner' => $p->partner, 'venue' => $p->venue,
            'beneficiary_group' => $p->beneficiary_group, 'status' => $p->status, 'activity_id' => $p->activity_id];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'objectives' => ['nullable', 'string', 'max:5000'], 'partner' => ['nullable', 'string', 'max:255'], 'sponsor' => ['nullable', 'string', 'max:255'],
            'venue' => ['nullable', 'string', 'max:255'], 'programme_date' => ['required', 'date'], 'beneficiary_group' => ['nullable', 'string', 'max:255'], 'target_count' => ['nullable', 'integer', 'min:0']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
