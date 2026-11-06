<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\PurchasingDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Finance\Http\Presenters\FinanceOptions;
use App\Modules\Finance\Http\Presenters\FinancePresenter;
use App\Modules\Finance\Models\Bill;
use App\Modules\Finance\Services\BillService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Vendor bills / payables. @see SRS M01-05, M10-03 */
final class BillController
{
    use AuthorizesRequests, HandlesDomainErrors;

    public function __construct(private readonly BillService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Bill::class);
        $f = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'q' => ['nullable', 'string', 'max:60'], 'sort' => ['nullable', 'in:bill_no,due_date,total_amount'], 'dir' => ['nullable', 'in:asc,desc'], 'per_page' => ['nullable', 'in:10,25,50,100'], 'page' => ['nullable', 'integer']]);
        $q = Bill::query()->with('vendor:id,name')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('bill_no', 'ilike', "%{$v}%")->orWhere('vendor_invoice_no', 'ilike', "%{$v}%")))
            ->orderBy($f['sort'] ?? 'due_date', ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');

        return Inertia::render('Finance/Bills/Index', [
            'bills' => $q->paginate((int) ($f['per_page'] ?? 25))->withQueryString()->through(fn (Bill $b) => FinancePresenter::bill($b)),
            'filters' => $f, 'can' => ['create' => $request->user()?->can('create', Bill::class) ?? false],
        ]);
    }

    /** Optional ?goods_receipt_id= pre-fills vendor, PO and lines from an M10 goods/service receipt (M10-02 chain). */
    public function create(Request $request, PurchasingDirectory $purchasing): Response
    {
        $this->authorize('create', Bill::class);
        $prefill = $request->filled('goods_receipt_id') ? $purchasing->receiptForBilling((int) $request->query('goods_receipt_id')) : null;

        return Inertia::render('Finance/Bills/Create', ['vendors' => FinanceOptions::vendors(), 'categories' => FinanceOptions::dictionary('expense_category'), 'prefill' => $prefill]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Bill::class);
        $data = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'], 'vendor_invoice_no' => ['required', 'string', 'max:64'],
            'bill_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:bill_date'], 'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:50'], 'lines.*.description' => ['required', 'string', 'max:300'],
            'lines.*.quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,4})?$/'], 'lines.*.unit_price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'lines.*.category_id' => ['nullable', 'exists:dictionary_items,id'], 'lines.*.capitalization_class' => ['nullable', 'in:CAPITAL_ASSET,LOW_VALUE_EQUIPMENT,CONSUMABLE,EXPENSE'],
            'goods_receipt_id' => ['nullable', 'integer'],
        ]);
        /** @var User $user */
        $user = $request->user();
        if (! empty($data['goods_receipt_id'])) {
            $receipt = app(PurchasingDirectory::class)->receiptForBilling((int) $data['goods_receipt_id']);
            if ($receipt === null || $receipt['vendor_id'] !== (int) $data['vendor_id']) {
                return back()->withErrors(['general' => __('The goods receipt does not match this vendor.')])->withInput();
            }
            $data['vendor_po_id'] = $receipt['vendor_po_id'];
        }

        return $this->attempt(fn () => redirect()->route('finance.bills.show', $this->service->create($data, $user)), __('Bill saved as draft.'));
    }

    public function show(Request $request, Bill $bill, DocumentStore $documents, PaymentService $payments): Response
    {
        $this->authorize('view', $bill);
        $bill->load(['vendor', 'lines']);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Finance/Bills/Show', [
            'bill' => FinancePresenter::bill($bill, true) + ['unallocated' => in_array($bill->status->value, ['approved', 'partially_paid'], true) ? (string) $payments->unallocated('bill', $bill->id)->getAmount() : null],
            'documents' => $documents->linkedTo('bill', $bill->id),
            'can' => [
                'submit' => $bill->status->value === 'draft' && $user->can('update', $bill), 'approve' => $bill->status->value === 'pending_approval' && $user->can('approve', $bill),
                'return' => $bill->status->value === 'pending_approval' && $user->can('approve', $bill), 'cancel' => $bill->status->value === 'draft' && $user->can('update', $bill),
                'pay' => in_array($bill->status->value, ['approved', 'partially_paid'], true) && $user->can('pay', $bill), 'taxTreatment' => Gate::forUser($user)->allows('set-tax-treatment'),
            ],
        ]);
    }

    public function submit(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('update', $bill);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->submit($bill, $user), __('Bill submitted for approval.'));
    }

    public function approve(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('approve', $bill);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->approve($bill, $user, $request->string('override_reason')->toString() ?: null), __('Bill approved and posted to payables.'));
    }

    public function returnToDraft(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('approve', $bill);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->returnToDraft($bill, $user, $data['reason']), __('Bill returned to draft.'));
    }

    public function cancel(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('update', $bill);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();

        return $this->attempt(fn () => $this->service->cancel($bill, $user, $data['reason']), __('Bill cancelled.'));
    }

    public function taxTreatment(Request $request, Bill $bill): RedirectResponse
    {
        $this->authorize('view', $bill);
        abort_unless(Gate::forUser($request->user())->allows('set-tax-treatment'), 403);
        $data = $request->validate(['tax_treatment' => ['required', Rule::enum(TaxTreatment::class)], 'note' => ['nullable', 'string', 'max:500']]);
        $before = $bill->tax_treatment->value;
        $bill->forceFill(['tax_treatment' => $data['tax_treatment']])->save();
        Audit::log('tax_treatment', $bill, null, ['tax_treatment' => $before], ['tax_treatment' => $data['tax_treatment']], $data['note'] ?? null);

        return back()->with('success', __('Tax treatment updated.'));
    }
}
