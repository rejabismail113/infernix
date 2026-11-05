<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Http\Presenters\FinancePresenter;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Models\Claim;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payments & payment vouchers. POST /finance/payments and /post are replay-safe (Idempotency-Key header
 * or the form's _idempotency_key, generated when the form opened).
 *
 * @see SRS M01-05, M03-06, §5.1, UAT-11
 */
final class PaymentController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function __construct(private readonly PaymentService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Payment::class);
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:60'], 'sort' => ['nullable', 'in:payment_no,payment_date,amount'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $q = Payment::query()->with('bankAccount.companyAccount')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('payment_no', 'ilike', "%{$v}%")->orWhere('payee_name', 'ilike', "%{$v}%")))
            ->orderBy($f['sort'] ?? 'payment_date', ($f['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');

        return Inertia::render('Finance/Payments/Index', [
            'payments' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Payment $p) => FinancePresenter::payment($p)),
            'filters' => $f, 'can' => ['create' => $request->user()?->can('create', Payment::class) ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Payment::class);
        $open = fn (string $type) => $type === 'bill'
            ? Bill::query()->with('vendor:id,name')->whereIn('status', ['approved', 'partially_paid'])->orderBy('due_date')->get()->map(fn (Bill $b): array => ['target_type' => 'bill', 'target_id' => $b->id, 'reference' => $b->bill_no.' · '.$b->vendor_invoice_no, 'payee_type' => 'vendor', 'payee_id' => $b->vendor_id, 'payee_name' => $b->vendor->name, 'due_date' => $b->due_date->toDateString(), 'unallocated' => (string) $this->service->unallocated('bill', $b->id)->getAmount()])
            : Claim::query()->with('staff:id,name')->where('status', 'finance_payable')->orderBy('approved_at')->get()->map(fn (Claim $c): array => ['target_type' => 'claim', 'target_id' => $c->id, 'reference' => $c->claim_no.' · '.$c->purpose, 'payee_type' => 'staff', 'payee_id' => $c->staff_user_id, 'payee_name' => $c->staff->name, 'due_date' => null, 'unallocated' => (string) $this->service->unallocated('claim', $c->id)->getAmount()]);

        return Inertia::render('Finance/Payments/Create', [
            'banks' => FinanceOptions::banks(),
            'openItems' => collect($open('bill'))->concat($open('claim'))->filter(fn (array $i): bool => $i['unallocated'] !== '0.00')->values(),
            'preselect' => ['target_type' => $request->query('target_type'), 'target_id' => $request->integer('target_id') ?: null],
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Payment::class);
        $data = $request->validate([
            'payment_date' => ['required', 'date'], 'bank_account_id' => ['required', 'exists:bank_accounts,id'], 'method' => ['required', 'in:transfer,duitnow,cheque,cash,card'],
            'reference' => ['nullable', 'string', 'max:120'], 'payee_type' => ['required', 'in:vendor,staff,owner,other'], 'payee_id' => ['nullable', 'integer'],
            'payee_name' => ['required', 'string', 'max:190'], 'memo' => ['nullable', 'string', 'max:500'],
            'allocations' => ['required', 'array', 'min:1', 'max:50'], 'allocations.*.target_type' => ['required', 'in:bill,claim'],
            'allocations.*.target_id' => ['required', 'integer'], 'allocations.*.amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => redirect()->route('finance.payments.show', $this->service->create($data, $user)), __('Payment voucher drafted. Submit it for approval.'));
    }

    public function show(Request $request, Payment $payment): Response
    {
        $this->authorize('view', $payment);
        $payment->load(['allocations', 'bankAccount.companyAccount']);
        /** @var User $user */
        $user = $request->user();
        $s = $payment->status->value;

        return Inertia::render('Finance/Payments/Show', [
            'payment' => FinancePresenter::payment($payment, true),
            'idempotencyKey' => (string) Str::uuid(),
            'can' => [
                'submit' => $s === 'draft' && $user->can('update', $payment),
                'approve' => $s === 'pending_approval' && $user->can('approve', $payment),
                'isMaker' => $payment->created_by === $user->id,
                'isOwner' => $user->isOwner(),
                'post' => $s === 'approved' && $user->can('pay', $payment),
                'cancel' => in_array($s, ['draft', 'pending_approval', 'approved'], true) && $user->can('update', $payment),
            ],
        ]);
    }

    public function submit(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->submit($payment, $user), __('Submitted for approval.'));
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('approve', $payment);
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->approve($payment, $user, $data['override_reason'] ?? null), __('Payment approved.'));
    }

    public function post(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('pay', $payment);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => redirect()->route('finance.payments.show', $this->service->post($payment, $user)), __('Payment released and posted. Voucher generated.'));
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('update', $payment);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->cancel($payment, $user, $data['reason']), __('Payment cancelled; its allocations are released.'));
    }
}
