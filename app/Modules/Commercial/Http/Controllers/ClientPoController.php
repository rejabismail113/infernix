<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Http\Presenters\CommercialPresenter;
use App\Modules\Commercial\Models\ClientPurchaseOrder;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Services\GeneratorService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Incoming client POs / LOAs (M03-05) — distinct from outgoing vendor POs. @see SRS M03-05, M02-02 */
final class ClientPoController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly GeneratorService $generator, private readonly ClientDirectory $clients, private readonly ProjectDirectory $projects, private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ClientPurchaseOrder::class);
        /** @var User $user */
        $user = $request->user();
        $scope = $this->permissions->scopeFor($user, 'client_pos', PermissionAction::View);

        return Inertia::render('Commercial/ClientPos/Index', [
            'pos' => ClientPurchaseOrder::query()->when($scope !== RecordScope::All, fn ($q) => $q->where('created_by', $user->id))->orderByDesc('id')->limit(300)->get()
                ->map(fn (ClientPurchaseOrder $po): array => CommercialPresenter::row($po) + ['external_ref' => $po->external_ref, 'po_date' => $po->effective_date?->toDateString(), 'quotation_id' => $po->related_document_id, 'project_id' => $po->project_id]),
            'clients' => $this->clients->options($user),
            'quotations' => Quotation::query()->whereIn('status', [DocumentStatus::Issued->value, DocumentStatus::Accepted->value])->orderByDesc('id')->limit(300)->get()
                ->map(fn (Quotation $q): array => ['id' => $q->id, 'label' => $q->displayNo().' '.$q->counterpartyName(), 'client_id' => $q->client_id]),
            'projects' => $this->projects->options($user),
            'preselect' => ['quotation_id' => $request->integer('quotation_id') ?: null],
            'can' => ['create' => $user->can('create', ClientPurchaseOrder::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ClientPurchaseOrder::class);
        $data = $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id'], 'external_ref' => ['required', 'string', 'max:80'], 'po_date' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'quotation_id' => ['nullable', 'integer', 'exists:commercial_documents,id'], 'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'title' => ['nullable', 'string', 'max:190']]);
        /** @var User $user */
        $user = $request->user();
        if (! empty($data['project_id'])) {
            abort_unless(in_array((int) $data['project_id'], array_column($this->projects->options($user), 'id'), true), 403);
        }

        return $this->attempt(fn () => $this->generator->recordClientPo($data, $user), __('Client PO recorded. Attach the scanned PO / LOA on the quotation or project.'));
    }
}
