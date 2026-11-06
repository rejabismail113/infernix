<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Models\DictionaryItem;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Http\Presenters\CommercialPresenter;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\CreditNote;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Models\ReceiptAllocation;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\DocumentRegistry;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Quotations, invoices and credit notes share one builder/approval/issue flow; the route supplies the type.
 * Every action is policy-checked against the type's own matrix resource (deny-by-default, CLAUDE.md rule 3).
 *
 * @see SRS M03-01, M03-02, M03-03, M03-07, UAT-04, UAT-06, UAT-11, M11-05
 */
final class DocumentController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    private const ROUTES = ['quotation' => 'quotations', 'invoice' => 'invoices', 'credit_note' => 'credit-notes'];

    private const SORTS = ['document_no', 'issue_date', 'due_date', 'total', 'created_at', 'status'];

    public function __construct(
        private readonly CommercialDocumentService $service,
        private readonly ClientDirectory $clients,
        private readonly ProjectDirectory $projects,
        private readonly LedgerGateway $ledger,
        private readonly PermissionService $permissions,
    ) {}

    public function index(Request $request): Response
    {
        $type = $this->type($request);
        $class = DocumentRegistry::modelFor($type);
        $this->authorize('viewAny', $class);
        /** @var User $user */
        $user = $request->user();
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'settlement' => ['nullable', 'in:unpaid,partially_paid,paid'], 'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'integer', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $scope = $this->permissions->scopeFor($user, $type->resource(), PermissionAction::View);
        $q = $class::query()
            ->when($scope !== RecordScope::All, fn ($q) => $q->where('created_by', $user->id))
            ->when($f['status'] ?? null, fn ($q, $v) => $v === 'overdue'
                ? $q->whereIn('status', ['issued', 'accepted'])->where('settlement_status', '!=', 'paid')->whereDate('due_date', '<', today())
                : $q->where('status', $v))
            ->when($f['settlement'] ?? null, fn ($q, $v) => $q->where('settlement_status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('document_no', 'ilike', "%{$v}%")->orWhere('title', 'ilike', "%{$v}%")->orWhereRaw("counterparty_snapshot->>'name' ILIKE ?", ["%{$v}%"])))
            ->orderBy($f['sort'] ?? 'created_at', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return Inertia::render('Commercial/Documents/Index', [
            'type' => $type->value, 'label' => $type->label(), 'routeBase' => self::ROUTES[$type->value],
            'documents' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (CommercialDocument $d): array => CommercialPresenter::row($d)),
            'filters' => $f,
            'can' => ['create' => $type !== DocumentType::CreditNote && $user->can('create', $class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $type = $this->type($request);
        abort_if($type === DocumentType::CreditNote, 404); // credit notes start from an invoice
        $this->authorize('create', DocumentRegistry::modelFor($type));
        /** @var User $user */
        $user = $request->user();
        $project = $request->integer('project_id') ? $this->projects->snapshot($request->integer('project_id')) : null;
        if ($project !== null) {
            $this->assertProjectVisible($user, $project['id']);
        }

        return Inertia::render('Commercial/Documents/Form', [
            'type' => $type->value, 'label' => $type->label(), 'routeBase' => self::ROUTES[$type->value], 'document' => null,
            'prefill' => $project === null ? null : ['client_id' => $project['client_id'], 'project_id' => $project['id'], 'title' => $project['name'],
                'lines' => [['description' => $project['name'], 'quantity' => '1', 'unit' => 'lot', 'unit_price' => $project['contract_value'], 'discount' => '0.00']]],
            ...$this->options($type, $user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $type = $this->type($request);
        abort_if($type === DocumentType::CreditNote, 404);
        $this->authorize('create', DocumentRegistry::modelFor($type));
        $data = $this->validated($request, $type);
        /** @var User $user */
        $user = $request->user();
        if (($data['project_id'] ?? null) !== null) {
            $this->assertProjectVisible($user, (int) $data['project_id']);
        }

        return $this->attempt(function () use ($type, $data, $user): RedirectResponse {
            $doc = $this->service->createDraft($type, $data, $user);

            return redirect()->route('commercial.'.self::ROUTES[$type->value].'.show', $doc->id)->with('success', __(':type draft saved.', ['type' => $type->label()]));
        }, '');
    }

    public function show(Request $request, string $document, DocumentStore $documents): Response
    {
        $type = $this->type($request);
        $doc = $this->find($type, $document);
        $this->authorize('view', $doc);
        /** @var User $user */
        $user = $request->user();
        $status = $doc->status;
        $editable = $user->can('update', $doc);
        $approver = $user->can('approve', $doc);

        $related = [];
        if ($doc instanceof Invoice) {
            $related['receipts'] = Receipt::query()->whereIn('id', ReceiptAllocation::query()->where('invoice_id', $doc->id)->pluck('receipt_id'))->orderBy('receipt_date')->get()
                ->map(fn (Receipt $r): array => CommercialPresenter::receipt($r) + ['allocated' => (string) ReceiptAllocation::query()->where('receipt_id', $r->id)->where('invoice_id', $doc->id)->firstOrFail()->amount->getAmount()])->all();
            $related['credit_notes'] = CreditNote::query()->where('related_document_id', $doc->id)->orderBy('id')->get()->map(fn (CreditNote $c): array => CommercialPresenter::row($c))->all();
        }
        if ($doc->source_type === 'quotation' && $doc->source_id !== null) {
            $sq = Quotation::query()->find($doc->source_id);
            $related['source_quotation'] = $sq ? ['id' => $sq->id, 'number' => $sq->displayNo()] : null;
        }
        if ($doc instanceof CreditNote) {
            $inv = Invoice::query()->find($doc->related_document_id);
            $related['invoice'] = $inv ? ['id' => $inv->id, 'number' => $inv->displayNo(), 'outstanding' => (string) $inv->outstanding()->getAmount()] : null;
        }
        if ($doc instanceof Quotation) {
            $related['revisions'] = Quotation::query()->where(fn ($q) => $doc->document_no !== null ? $q->where('document_no', $doc->document_no) : $q->whereKey($doc->id))
                ->orderBy('revision_no')->get()->map(fn (Quotation $r): array => CommercialPresenter::row($r))->all();
            $related['project_id'] = $this->projects->idForQuotation($doc->id);
            $related['invoices'] = Invoice::query()->where('source_type', 'quotation')->where('source_id', $doc->id)->get()->map(fn (Invoice $i): array => CommercialPresenter::row($i))->all();
        }

        return Inertia::render('Commercial/Documents/Show', [
            'type' => $type->value, 'label' => $type->label(), 'routeBase' => self::ROUTES[$type->value],
            'document' => CommercialPresenter::detail($doc, $user), 'approvals' => CommercialPresenter::approvals($doc), 'related' => $related,
            'journal' => $doc->journal_id !== null && $this->permissions->can($user, PermissionAction::View, 'journals') ? ['id' => $doc->journal_id, 'no' => $this->ledger->journalNo($doc->journal_id)] : null,
            'attachments' => $documents->linkedTo($type->value, $doc->id),
            'idempotencyKey' => (string) Str::uuid(),
            'can' => [
                'edit' => $status === DocumentStatus::Draft && $editable,
                'submit' => $status === DocumentStatus::Draft && $editable,
                'discard' => $status === DocumentStatus::Draft && $editable,
                'approve' => $status === DocumentStatus::PendingApproval && $approver,
                'return' => in_array($status, [DocumentStatus::PendingApproval, DocumentStatus::Approved], true) && ($approver || $editable),
                'issue' => $status === DocumentStatus::Approved && $editable,
                'respond' => $doc instanceof Quotation && $status === DocumentStatus::Issued && $editable,
                'revise' => $doc instanceof Quotation && in_array($status, [DocumentStatus::Issued, DocumentStatus::Rejected], true) && $editable,
                'convert' => $doc instanceof Quotation && $status === DocumentStatus::Accepted && $user->can('create', Invoice::class),
                'convertProject' => $doc instanceof Quotation && $status === DocumentStatus::Accepted && $this->permissions->can($user, PermissionAction::Create, 'projects'),
                'void' => in_array($status, [DocumentStatus::Issued, DocumentStatus::Accepted, DocumentStatus::Rejected], true) && $approver,
                'creditNote' => $doc instanceof Invoice && in_array($status, [DocumentStatus::Issued, DocumentStatus::Accepted], true) && $doc->outstanding()->isPositive() && $user->can('create', CreditNote::class),
                'receipt' => $doc instanceof Invoice && in_array($status, [DocumentStatus::Issued, DocumentStatus::Accepted], true) && $doc->outstanding()->isPositive() && $user->can('create', Receipt::class),
                'regenerate' => $status->isIssued() && $doc->pdf_document_id === null && $editable,
                'attach' => $editable,
            ],
        ]);
    }

    public function edit(Request $request, string $document): Response
    {
        $type = $this->type($request);
        $doc = $this->find($type, $document);
        $this->authorize('update', $doc);
        abort_unless($doc->isEditable(), 403, 'Only drafts can be edited.');
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Commercial/Documents/Form', [
            'type' => $type->value, 'label' => $type->label(), 'routeBase' => self::ROUTES[$type->value],
            'document' => CommercialPresenter::detail($doc, $user), 'prefill' => null, ...$this->options($type, $user),
        ]);
    }

    public function update(Request $request, string $document): RedirectResponse
    {
        $type = $this->type($request);
        $doc = $this->find($type, $document);
        $this->authorize('update', $doc);
        $data = $this->validated($request, $type);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($doc, $data, $user, $type): RedirectResponse {
            $this->service->updateDraft($doc, $data, $user);

            return redirect()->route('commercial.'.self::ROUTES[$type->value].'.show', $doc->id)->with('success', __('Draft updated.'));
        }, '');
    }

    public function destroy(Request $request, string $document): RedirectResponse
    {
        $type = $this->type($request);
        $doc = $this->find($type, $document);
        $this->authorize('update', $doc);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($doc, $user, $type): RedirectResponse {
            $this->service->discardDraft($doc, $user);

            return redirect()->route('commercial.'.self::ROUTES[$type->value].'.index')->with('success', __('Draft discarded.'));
        }, '');
    }

    public function submit(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $this->authorize('update', $doc);

        return $this->attempt(fn () => $this->service->submit($doc, $this->user($request)), __('Submitted for approval.'));
    }

    public function approve(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $this->authorize('approve', $doc);
        $reason = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']])['override_reason'] ?? null;

        return $this->attempt(fn () => $this->service->approve($doc, $this->user($request), $reason), __('Approved.'));
    }

    public function returnToDraft(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $user = $this->user($request);
        abort_unless($user->can('approve', $doc) || $user->can('update', $doc), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->service->returnToDraft($doc, $user, $reason), __('Returned to draft.'));
    }

    public function issue(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $this->authorize('update', $doc);
        $date = $request->validate(['issue_date' => ['nullable', 'date']])['issue_date'] ?? null;

        return $this->attempt(fn () => $this->service->issue($doc, $this->user($request), $date), __(':type issued.', ['type' => $doc->type->label()]));
    }

    public function respond(Request $request, string $document): RedirectResponse
    {
        /** @var Quotation $q */
        $q = $this->find(DocumentType::Quotation, $document);
        $this->authorize('update', $q);
        $data = $request->validate(['accepted' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->recordClientResponse($q, (bool) $data['accepted'], $this->user($request), $data['note'] ?? null), $data['accepted'] ? __('Marked as accepted by the client.') : __('Marked as rejected by the client.'));
    }

    public function revise(Request $request, string $document): RedirectResponse
    {
        /** @var Quotation $q */
        $q = $this->find(DocumentType::Quotation, $document);
        $this->authorize('update', $q);
        $this->authorize('create', Quotation::class);

        return $this->attempt(function () use ($q, $request): RedirectResponse {
            $rev = $this->service->revise($q, $this->user($request));

            return redirect()->route('commercial.quotations.edit', $rev->id)->with('success', __('Revision :n created as a draft.', ['n' => $rev->revision_no]));
        }, '');
    }

    public function convertToInvoice(Request $request, string $document): RedirectResponse
    {
        /** @var Quotation $q */
        $q = $this->find(DocumentType::Quotation, $document);
        $this->authorize('view', $q);
        $this->authorize('create', Invoice::class);

        return $this->attempt(function () use ($q, $request): RedirectResponse {
            $inv = $this->service->convertToInvoice($q, $this->user($request));

            return redirect()->route('commercial.invoices.show', $inv->id)->with('success', __('Invoice draft created from :q.', ['q' => $q->displayNo()]));
        }, '');
    }

    public function convertToProject(Request $request, string $document): RedirectResponse
    {
        /** @var Quotation $q */
        $q = $this->find(DocumentType::Quotation, $document);
        $this->authorize('view', $q);
        abort_unless($this->permissions->can($this->user($request), PermissionAction::Create, 'projects'), 403);
        $name = $request->validate(['name' => ['nullable', 'string', 'max:190']])['name'] ?? null;

        return $this->attempt(function () use ($q, $request, $name): RedirectResponse {
            $id = $this->service->convertToProject($q, $this->user($request), $name);

            return redirect()->route('projects.projects.show', $id)->with('success', __('Project created from :q.', ['q' => $q->displayNo()]));
        }, '');
    }

    public function void(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $this->authorize('approve', $doc);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->service->void($doc, $this->user($request), $reason), __('Voided. The number is kept and marked VOID.'));
    }

    public function creditNote(Request $request, string $document): RedirectResponse
    {
        /** @var Invoice $inv */
        $inv = $this->find(DocumentType::Invoice, $document);
        $this->authorize('view', $inv);
        $this->authorize('create', CreditNote::class);

        return $this->attempt(function () use ($inv, $request): RedirectResponse {
            $cn = $this->service->draftCreditNote($inv, $this->user($request));

            return redirect()->route('commercial.credit-notes.edit', $cn->id)->with('success', __('Credit note draft created; adjust the lines to the amount being credited.'));
        }, '');
    }

    public function regenerate(Request $request, string $document): RedirectResponse
    {
        $doc = $this->find($this->type($request), $document);
        $this->authorize('update', $doc);

        return $this->attempt(fn () => $this->service->regeneratePdf($doc, $this->user($request)), __('PDF generated.'));
    }

    /** The route supplies the document type (quotations / invoices / credit-notes groups). */
    private function type(Request $request): DocumentType
    {
        return DocumentType::from((string) $request->route('docType'));
    }

    /** Projects are owned by M02: a project may be referenced only if it is in the user's visible options. */
    private function assertProjectVisible(User $user, int $projectId): void
    {
        abort_unless(in_array($projectId, array_column($this->projects->options($user), 'id'), true), 403);
    }

    private function find(DocumentType $type, string|int $id): CommercialDocument
    {
        return DocumentRegistry::modelFor($type)::query()->findOrFail((int) $id);
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array{client_id?: int|null, project_id?: int|null, title?: string|null, terms?: string|null, internal_notes?: string|null, valid_until?: string|null, due_date?: string|null, lines: list<array<string, mixed>>} */
    private function validated(Request $request, DocumentType $type): array
    {
        /** @var array{client_id?: int|null, project_id?: int|null, title?: string|null, terms?: string|null, internal_notes?: string|null, valid_until?: string|null, due_date?: string|null, lines: list<array<string, mixed>>} $data */
        $data = $request->validate([
            'client_id' => [$type === DocumentType::CreditNote ? 'nullable' : 'required', 'integer', 'exists:clients,id'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'title' => ['nullable', 'string', 'max:190'], 'terms' => ['nullable', 'string', 'max:4000'], 'internal_notes' => ['nullable', 'string', 'max:4000'],
            'valid_until' => ['nullable', 'date'], 'due_date' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.description' => ['required', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'lines.*.unit' => ['nullable', 'string', 'max:20'],
            'lines.*.unit_price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.discount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.tax_code_id' => ['nullable', 'integer', 'exists:dictionary_items,id'],
            'lines.*.revenue_account_id' => ['nullable', 'integer', 'exists:chart_accounts,id'],
            'lines.*.source_line_id' => ['nullable', 'integer', 'exists:document_lines,id'],
        ]);
        $data['lines'] = array_values($data['lines']);

        return $data;
    }

    /** @return array<string, mixed> */
    private function options(DocumentType $type, User $user): array
    {
        return [
            'clients' => $this->clients->options($user),
            'projects' => $this->projects->options($user),
            'taxCodes' => DictionaryItem::query()->whereRelation('dictionary', 'code', 'tax_code')->where('active', true)->orderBy('sort_order')->get()
                ->map(fn (DictionaryItem $i): array => ['id' => $i->id, 'label' => $i->label_en, 'rate' => (string) ($i->meta['rate'] ?? '0')])->values()->all(),
            'revenueAccounts' => $type === DocumentType::Quotation ? [] : $this->ledger->revenueAccountOptions(),
            'defaultRevenueAccountId' => $type === DocumentType::Quotation ? null : $this->ledger->defaultRevenueAccountId(),
            'canCreateClient' => $this->permissions->can($user, PermissionAction::Create, 'clients'),
        ];
    }
}
