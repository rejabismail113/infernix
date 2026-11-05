<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Http\Presenters\FinancePresenter;
use App\Modules\Finance\Models\Transaction;
use App\Modules\Finance\Services\TransactionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Income/receipts and expense registers. @see SRS M01-01, M01-02, M01-09, M01-10 */
final class TransactionController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function __construct(private readonly TransactionService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Transaction::class);
        $f = $request->validate(['direction' => ['nullable', 'in:inflow,outflow'], 'q' => ['nullable', 'string', 'max:60'], 'status' => ['nullable', 'in:draft,posted,reversed,cancelled'], 'tax_treatment' => ['nullable', Rule::enum(TaxTreatment::class)], 'sort' => ['nullable', 'in:transaction_date,gross_amount,transaction_no'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $direction = $f['direction'] ?? 'outflow';
        $q = Transaction::query()->where('direction', $direction)
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('transaction_no', 'ilike', "%{$v}%")->orWhere('description', 'ilike', "%{$v}%")->orWhere('counterparty_name', 'ilike', "%{$v}%")))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['tax_treatment'] ?? null, fn ($q, $v) => $q->where('tax_treatment', $v))
            ->orderBy($f['sort'] ?? 'transaction_date', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return Inertia::render('Finance/Transactions/Index', [
            'direction' => $direction,
            'transactions' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Transaction $t) => FinancePresenter::transaction($t)),
            'filters' => $f + ['direction' => $direction],
            'can' => ['create' => $request->user()?->can('create', Transaction::class) ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Transaction::class);

        return Inertia::render('Finance/Transactions/Create', [
            'direction' => $request->query('direction') === 'inflow' ? 'inflow' : 'outflow',
            'banks' => FinanceOptions::banks(), 'categories' => FinanceOptions::dictionary('expense_category'), 'costCentres' => FinanceOptions::dictionary('cost_centre'),
            'vendors' => FinanceOptions::vendors(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Transaction::class);
        $data = $request->validate([
            'direction' => ['required', 'in:inflow,outflow'],
            'inflow_type' => ['nullable', 'required_if:direction,inflow', 'in:sales_project,sales_training,grant,other_income,capital_injection,loan,refund'],
            'payment_source' => ['nullable', 'required_if:direction,outflow', 'in:DIRECT_COMPANY,OWNER_PAID,STAFF_PAID_REIMBURSABLE'],
            'capitalization_class' => ['nullable', 'required_if:direction,outflow', 'in:CAPITAL_ASSET,LOW_VALUE_EQUIPMENT,CONSUMABLE,EXPENSE,OWNER_DRAWING'],
            'transaction_date' => ['required', 'date'], 'bank_account_id' => ['nullable', 'exists:bank_accounts,id'], 'payment_method' => ['nullable', 'string', 'max:24'],
            'bank_reference' => ['nullable', 'string', 'max:120'], 'counterparty_name' => ['nullable', 'string', 'max:190'], 'vendor_id' => ['nullable', 'exists:vendors,id'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*.description' => ['required', 'string', 'max:300'],
            'lines.*.amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'lines.*.category_id' => ['nullable', 'exists:dictionary_items,id'], 'lines.*.cost_centre_id' => ['nullable', 'exists:dictionary_items,id'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(function () use ($data, $user, $request) {
            $t = $this->service->create($data, $user);
            if ($request->boolean('post_now') && $user->can('approve', $t)) {
                $this->service->post($t, $user);
            }

            return redirect()->route('finance.transactions.show', $t);
        }, __('Saved.'));
    }

    public function show(Request $request, Transaction $transaction, DocumentStore $documents): Response
    {
        $this->authorize('view', $transaction);
        $transaction->load(['lines', 'bankAccount.companyAccount']);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Finance/Transactions/Show', [
            'transaction' => FinancePresenter::transaction($transaction) + [
                'bank' => $transaction->bankAccount?->label(),
                'lines' => $transaction->lines->map(fn ($l) => ['line_no' => $l->line_no, 'description' => $l->description, 'amount' => FinancePresenter::m($l->amount), 'category_id' => $l->category_id])->all(),
            ],
            'documents' => $documents->linkedTo('transaction', $transaction->id),
            'categories' => FinanceOptions::dictionary('expense_category'),
            'can' => [
                'post' => $transaction->status->value === 'draft' && $user->can('approve', $transaction),
                'reverse' => $transaction->status->value === 'posted' && $user->can('approve', $transaction),
                'cancel' => $transaction->status->value === 'draft' && $user->can('update', $transaction),
                'taxTreatment' => Gate::forUser($user)->allows('set-tax-treatment'),
                'attach' => $user->can('update', $transaction),
            ],
        ]);
    }

    public function post(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->post($transaction, $user), __('Posted to the ledger.'));
    }

    public function reverse(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('approve', $transaction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->reverse($transaction, $user, $data['reason']), __('Reversed. The correction is posted today in the open period.'));
    }

    public function cancel(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('update', $transaction);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->cancel($transaction, $user, $data['reason']), __('Draft cancelled.'));
    }

    public function taxTreatment(Request $request, Transaction $transaction): RedirectResponse
    {
        $this->authorize('view', $transaction);
        abort_unless(Gate::forUser($request->user())->allows('set-tax-treatment'), 403);
        $data = $request->validate(['tax_treatment' => ['required', Rule::enum(TaxTreatment::class)], 'note' => ['nullable', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();
        $this->service->setTaxTreatment($transaction, TaxTreatment::from($data['tax_treatment']), $user, $data['note'] ?? null);

        return back()->with('success', __('Tax treatment updated.'));
    }
}
