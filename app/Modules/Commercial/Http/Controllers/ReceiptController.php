<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Http\Presenters\CommercialPresenter;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Models\ReceiptAllocation;
use App\Modules\Commercial\Services\ReceiptService;
use App\Modules\Finance\Services\LedgerGateway;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Official receipts: recording one IS the cash posting (Dr Bank / Cr AR) — idempotent (§5.1), never a draft.
 *
 * @see SRS M03-04, UAT-04, WF-04
 */
final class ReceiptController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly ReceiptService $service, private readonly LedgerGateway $ledger, private readonly ClientDirectory $clients, private readonly PermissionService $permissions) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Receipt::class);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:posted,void'], 'sort' => ['nullable', 'in:receipt_no,receipt_date,amount'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'integer', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $q = Receipt::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('receipt_no', 'ilike', "%{$v}%")->orWhere('payer_name', 'ilike', "%{$v}%")->orWhere('reference', 'ilike', "%{$v}%")))
            ->orderBy($f['sort'] ?? 'receipt_date', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return Inertia::render('Commercial/Receipts/Index', [
            'receipts' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Receipt $r): array => CommercialPresenter::receipt($r)),
            'filters' => $f, 'can' => ['create' => $request->user()?->can('create', Receipt::class) ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Receipt::class);
        /** @var User $user */
        $user = $request->user();
        $open = Invoice::query()->whereIn('status', [DocumentStatus::Issued->value, DocumentStatus::Accepted->value])->where('settlement_status', '!=', 'paid')->orderBy('due_date')->limit(500)->get()
            ->map(fn (Invoice $i): array => ['id' => $i->id, 'number' => $i->displayNo(), 'client_id' => $i->client_id, 'client' => $i->counterpartyName(), 'due_date' => $i->due_date?->toDateString(), 'outstanding' => (string) $i->outstanding()->getAmount()])->values();

        return Inertia::render('Commercial/Receipts/Create', [
            'banks' => $this->ledger->bankAccountOptions(), 'clients' => $this->clients->options($user), 'openInvoices' => $open,
            'preselect' => $request->integer('invoice_id') ?: null, 'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Receipt::class);
        $data = $request->validate([
            'receipt_date' => ['required', 'date'], 'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'method' => ['required', 'in:transfer,duitnow,cheque,cash,card,fpx'], 'reference' => ['nullable', 'string', 'max:120'], 'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'payer_name' => ['nullable', 'string', 'max:190'], 'notes' => ['nullable', 'string', 'max:1000'],
            'allocations' => ['nullable', 'array', 'max:50'], 'allocations.*.invoice_id' => ['required', 'integer', 'exists:commercial_documents,id'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $key = $request->header('Idempotency-Key') ?? $request->input('_idempotency_key');

        return $this->attempt(function () use ($data, $user, $key): RedirectResponse {
            $receipt = $this->service->record($data, $user, is_string($key) ? $key : null);

            return redirect()->route('commercial.receipts.show', $receipt->id)->with('success', __('Receipt :no posted.', ['no' => $receipt->receipt_no]));
        }, '');
    }

    public function show(Request $request, Receipt $receipt, DocumentStore $documents): Response
    {
        $this->authorize('view', $receipt);
        /** @var User $user */
        $user = $request->user();
        $allocs = ReceiptAllocation::query()->where('receipt_id', $receipt->id)->get();
        $invoices = Invoice::query()->whereIn('id', $allocs->pluck('invoice_id'))->get()->keyBy('id');

        return Inertia::render('Commercial/Receipts/Show', [
            'receipt' => CommercialPresenter::receipt($receipt) + ['bank' => collect($this->ledger->bankAccountOptions())->firstWhere('id', $receipt->bank_account_id)['label'] ?? null, 'notes' => $receipt->notes],
            'allocations' => $allocs->map(fn (ReceiptAllocation $a): array => ['invoice_id' => $a->invoice_id, 'number' => $invoices[$a->invoice_id]?->displayNo(), 'amount' => (string) $a->amount->getAmount(), 'voided' => $a->voided_at !== null])->values(),
            'journal' => $this->permissions->can($user, PermissionAction::View, 'journals') ? ['id' => $receipt->journal_id, 'no' => $this->ledger->journalNo($receipt->journal_id), 'reversal_id' => $receipt->reversal_journal_id] : null,
            'attachments' => $documents->linkedTo('receipt', $receipt->id),
            'can' => ['void' => $receipt->status === 'posted' && $this->permissions->can($user, PermissionAction::Approve, 'invoices'), 'regenerate' => $receipt->status === 'posted' && $receipt->pdf_document_id === null && $user->can('update', $receipt)],
        ]);
    }

    public function void(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->authorize('view', $receipt);
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->permissions->can($user, PermissionAction::Approve, 'invoices'), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->attempt(fn () => $this->service->void($receipt, $user, $reason), __('Receipt voided; the posting was reversed and the number kept.'));
    }

    public function regenerate(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->authorize('update', $receipt);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->storePdf($receipt, $user), __('PDF generated.'));
    }
}
