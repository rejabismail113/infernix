<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Http\Presenters\CommercialPresenter;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\DocumentRegistry;
use App\Modules\Commercial\Services\GeneratorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Generator for staff memos, appointment letters and letters; register of all generated documents incl.
 * training certificates (issued from training sessions). Each type is gated by its own matrix resource.
 *
 * @see SRS M03-06, M03-07, NFR-02
 */
final class GeneratedDocumentController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    private const TYPES = ['memo', 'letter', 'appointment_letter', 'certificate'];

    private const DRAFTABLE = ['memo', 'letter', 'appointment_letter'];

    public function __construct(
        private readonly GeneratorService $generator,
        private readonly CommercialDocumentService $documents,
        private readonly PermissionService $permissions,
        private readonly StaffDirectory $staff,
        private readonly ClientDirectory $clients,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $types = array_values(array_filter(self::TYPES, fn (string $t): bool => $this->permissions->can($user, PermissionAction::View, DocumentType::from($t)->resource())));
        abort_if($types === [], 403);
        $f = $request->validate(['type' => ['nullable', 'in:'.implode(',', self::TYPES)], 'q' => ['nullable', 'string', 'max:100']]);
        $rows = collect();
        foreach ($f['type'] ?? null ? array_intersect($types, [$f['type']]) : $types as $t) {
            $type = DocumentType::from($t);
            $scope = $this->permissions->scopeFor($user, $type->resource(), PermissionAction::View);
            $class = DocumentRegistry::modelFor($type);
            $q = $class::query()
                ->when($scope === RecordScope::Own, fn ($q) => $q->where(fn ($w) => $w->where('created_by', $user->id)->orWhere(fn ($r) => $r->where('counterparty_type', 'staff')->where('counterparty_id', $this->staff->idForUser($user) ?? 0))))
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('document_no', 'ilike', "%{$v}%")->orWhere('subject', 'ilike', "%{$v}%")->orWhereRaw("counterparty_snapshot->>'name' ILIKE ?", ["%{$v}%"])))
                ->orderByDesc('id')->limit(200)->get();
            $rows = $rows->concat($q->map(fn (CommercialDocument $d): array => CommercialPresenter::row($d) + ['subject' => $d->subject, 'label' => $type->label(), 'pdf_document_id' => $d->pdf_document_id]));
        }

        return Inertia::render('Commercial/Generated/Index', [
            'documents' => $rows->sortByDesc('id')->values(), 'filters' => $f,
            'types' => array_map(fn (string $t): array => ['value' => $t, 'label' => DocumentType::from($t)->label(), 'create' => in_array($t, self::DRAFTABLE, true) && $this->permissions->can($user, PermissionAction::Create, DocumentType::from($t)->resource())], $types),
        ]);
    }

    public function create(Request $request): Response
    {
        $type = DocumentType::from($request->validate(['type' => ['required', 'in:'.implode(',', self::DRAFTABLE)]])['type']);
        $this->authorize('create', DocumentRegistry::modelFor($type));
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Commercial/Generated/Form', ['type' => $type->value, 'label' => $type->label(), 'document' => null, ...$this->options($user)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = DocumentType::from($request->validate(['type' => ['required', 'in:'.implode(',', self::DRAFTABLE)]])['type']);
        $this->authorize('create', DocumentRegistry::modelFor($type));
        $data = $this->validated($request, $type);

        return $this->attempt(function () use ($type, $data, $request): RedirectResponse {
            $doc = $this->generator->createDraft($type, $data, $this->user($request));

            return redirect()->route('commercial.generated.show', $doc->id)->with('success', __('Draft saved.'));
        }, '');
    }

    public function show(Request $request, string $document, DocumentStore $store): Response
    {
        $doc = $this->find($document);
        $this->authorize('view', $doc);
        $user = $this->user($request);
        $editable = $doc->status === DocumentStatus::Draft && $user->can('update', $doc);

        return Inertia::render('Commercial/Generated/Show', [
            'document' => CommercialPresenter::detail($doc, $user), 'label' => $doc->type->label(),
            'verifyUrl' => $doc->verification_token !== null ? route('verify.certificate', $doc->verification_token) : null,
            'attachments' => $store->linkedTo($doc->type->value, $doc->id),
            'can' => ['edit' => $editable, 'issue' => $editable, 'void' => $doc->status === DocumentStatus::Issued && $user->can('update', $doc) && $this->permissions->scopeFor($user, $doc->type->resource(), PermissionAction::Edit) === RecordScope::All,
                'regenerate' => $doc->status->isIssued() && $doc->pdf_document_id === null && $user->can('update', $doc)],
        ]);
    }

    public function edit(Request $request, string $document): Response
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);
        abort_unless($doc->status === DocumentStatus::Draft, 403);
        $user = $this->user($request);

        return Inertia::render('Commercial/Generated/Form', ['type' => $doc->type->value, 'label' => $doc->type->label(), 'document' => CommercialPresenter::detail($doc, $user), ...$this->options($user)]);
    }

    public function update(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);
        $data = $this->validated($request, $doc->type);

        return $this->attempt(function () use ($doc, $data, $request): RedirectResponse {
            $this->generator->updateDraft($doc, $data, $this->user($request));

            return redirect()->route('commercial.generated.show', $doc->id)->with('success', __('Draft updated.'));
        }, '');
    }

    public function issue(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);

        return $this->attempt(fn () => $this->generator->issue($doc, $this->user($request)), __(':type issued.', ['type' => $doc->type->label()]));
    }

    public function void(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);
        abort_unless($this->permissions->scopeFor($this->user($request), $doc->type->resource(), PermissionAction::Edit) === RecordScope::All, 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->documents->void($doc, $this->user($request), $reason), __('Voided; the number is kept.'));
    }

    public function destroy(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);

        return $this->attempt(function () use ($doc, $request): RedirectResponse {
            $this->documents->discardDraft($doc, $this->user($request));

            return redirect()->route('commercial.generated.index')->with('success', __('Draft discarded.'));
        }, '');
    }

    public function regenerate(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($document);
        $this->authorize('update', $doc);

        return $this->attempt(fn () => $this->documents->regeneratePdf($doc, $this->user($request)), __('PDF generated.'));
    }

    private function find(string $id): CommercialDocument
    {
        $doc = DocumentRegistry::find((int) $id);
        abort_if($doc === null || ! in_array($doc->type->value, self::TYPES, true), 404);

        return $doc;
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array{staff_profile_id?: int|null, recipient_name?: string|null, recipient_address?: string|null, client_id?: int|null, subject: string, body?: string|null, effective_date?: string|null, project_id?: int|null} */
    private function validated(Request $request, DocumentType $type): array
    {
        $staffRequired = in_array($type, [DocumentType::Memo, DocumentType::AppointmentLetter], true);

        /** @var array{staff_profile_id?: int|null, recipient_name?: string|null, recipient_address?: string|null, client_id?: int|null, subject: string, body?: string|null, effective_date?: string|null, project_id?: int|null} $data */
        $data = $request->validate([
            'staff_profile_id' => [$staffRequired ? 'required' : 'prohibited', 'integer', 'exists:staff_profiles,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'], 'recipient_name' => ['nullable', 'string', 'max:190'], 'recipient_address' => ['nullable', 'string', 'max:500'],
            'subject' => ['required', 'string', 'max:190'], 'body' => [$type === DocumentType::AppointmentLetter ? 'nullable' : 'required', 'string', 'max:20000'],
            'effective_date' => ['nullable', 'date'], 'project_id' => ['nullable', 'integer', 'exists:projects,id'],
        ]);

        return $data;
    }

    /** @return array<string, mixed> */
    private function options(User $user): array
    {
        return ['staff' => $this->staff->options($user), 'clients' => $this->clients->options($user)];
    }
}
