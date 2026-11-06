<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Kernel\Crm\Contracts\ActivityDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Crm\Contracts\VendorDirectory;
use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\PurchaseRequestLine;
use App\Modules\Procurement\Services\GoodsReceiptService;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Purchase requests and the full procurement audit chain PR → approval → vendor PO → goods receipt → bill →
 * payment (M10-02), plus goods/service receipts against issued POs.
 *
 * @see SRS M10-02, WF-02, UAT-11
 */
final class PurchaseRequestController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly PurchaseRequestService $service) {}

    public function index(Request $request, PermissionService $permissions, VendorDirectory $vendors, ProjectDirectory $projects, ActivityDirectory $activities, VendorPurchaseOrders $pos): Response
    {
        $this->authorize('viewAny', PurchaseRequest::class);
        $user = $this->user($request);
        $scope = $permissions->scopeFor($user, 'purchase_requests', PermissionAction::View);
        $rows = PurchaseRequest::query()->with('vendor')
            ->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->where('requested_by', $user->id)->when($scope === RecordScope::Assigned, fn ($x) => $x->orWhere('status', 'pending_approval'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByDesc('id')->limit(300)->get();
        $canCreate = $user->can('create', PurchaseRequest::class);

        return Inertia::render('Procurement/Requests/Index', [
            'requests' => $rows->map(fn (PurchaseRequest $pr): array => self::row($pr))->values(),
            'vendors' => $canCreate ? $vendors->options($user) : [], 'projects' => $canCreate ? $projects->options($user) : [], 'activities' => $canCreate ? $activities->options($user) : [],
            'openOrders' => $user->can('create', GoodsReceipt::class) ? $pos->openOrders() : [],
            'filters' => $request->only('status'),
            'can' => ['create' => $canCreate, 'receive' => $user->can('create', GoodsReceipt::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseRequest::class);

        return $this->attempt(fn () => redirect()->route('procurement.requests.show', $this->service->save(null, $request->validate($this->rules()), $this->user($request))->id), __('Purchase request saved as draft.'));
    }

    public function show(Request $request, PurchaseRequest $purchaseRequest, VendorPurchaseOrders $pos, FinanceRecords $finance, VendorDirectory $vendors, PermissionService $permissions): Response
    {
        $this->authorize('view', $purchaseRequest);
        $user = $this->user($request);
        $purchaseRequest->load('lines', 'vendor');
        $po = $purchaseRequest->vendor_po_id !== null ? $pos->snapshot($purchaseRequest->vendor_po_id) : null;
        $receipts = $purchaseRequest->vendor_po_id !== null ? GoodsReceipt::query()->with('lines')->where('vendor_po_id', $purchaseRequest->vendor_po_id)->orderBy('received_on')->get() : collect();
        $seeBills = $permissions->can($user, PermissionAction::View, 'bills');

        return Inertia::render('Procurement/Requests/Show', [
            'request' => self::row($purchaseRequest) + ['justification' => $purchaseRequest->justification, 'rejection_reason' => $purchaseRequest->rejection_reason, 'current_level' => $purchaseRequest->current_level,
                'project_id' => $purchaseRequest->project_id, 'activity_id' => $purchaseRequest->activity_id, 'vendor_id' => $purchaseRequest->vendor_id, 'requested_by' => $purchaseRequest->requested_by],
            'lines' => $purchaseRequest->lines->map(fn (PurchaseRequestLine $l): array => ['line_no' => $l->line_no, 'description' => $l->description, 'quantity' => (string) $l->quantity,
                'estimated_unit_price' => (string) $l->estimated_unit_price->getAmount(), 'estimated_amount' => (string) $l->estimated_amount->getAmount(), 'capitalization_class' => $l->capitalization_class])->values(),
            'approvals' => DB::table('approval_steps as s')->join('users as u', 'u.id', '=', 's.approver_id')->where('s.entity_type', 'purchase_request')->where('s.entity_id', $purchaseRequest->id)
                ->orderBy('s.level')->get(['s.level', 'u.name', 's.decided_at', 's.owner_override']),
            'po' => $po,
            'receipts' => $receipts->map(fn (GoodsReceipt $g): array => ['id' => $g->id, 'grn_no' => $g->grn_no, 'received_on' => $g->received_on->toDateString(), 'kind' => $g->kind, 'status' => $g->status,
                'lines' => $g->lines->map(fn (GoodsReceiptLine $l): array => ['description' => $l->description, 'quantity_ordered' => (string) $l->quantity_ordered, 'quantity_received' => (string) $l->quantity_received, 'condition' => $l->condition])->values(),
                'bill_url' => $seeBills && $permissions->can($user, PermissionAction::Create, 'bills') ? route('finance.bills.create', ['goods_receipt_id' => $g->id]) : null])->values(),
            'bills' => $seeBills && $purchaseRequest->vendor_po_id !== null ? $finance->billsForVendorPo($purchaseRequest->vendor_po_id) : null,
            'vendors' => $user->can('create', PurchaseRequest::class) ? $vendors->options($user) : [],
            'can' => ['edit' => $user->can('update', $purchaseRequest) && in_array($purchaseRequest->status, ['draft', 'rejected'], true),
                'approve' => $purchaseRequest->status === 'pending_approval' && $user->can('approve', $purchaseRequest),
                'order' => $purchaseRequest->status === 'approved' && $permissions->can($user, PermissionAction::Create, 'vendor_pos'),
                'receive' => $po !== null && in_array($po['status'], ['issued', 'accepted'], true) && $user->can('create', GoodsReceipt::class)],
        ]);
    }

    public function update(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        return $this->attempt(fn () => $this->service->save($purchaseRequest, $request->validate($this->rules()), $this->user($request)), __('Saved.'));
    }

    public function submit(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);

        return $this->attempt(fn () => $this->service->submit($purchaseRequest, $this->user($request)), __('Submitted for approval.'));
    }

    public function approve(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->approve($purchaseRequest, $this->user($request), $data['override_reason'] ?? null), __('Approved.'));
    }

    public function reject(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('approve', $purchaseRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->reject($purchaseRequest, $this->user($request), $data['reason']), __('Rejected.'));
    }

    public function cancel(Request $request, PurchaseRequest $purchaseRequest): RedirectResponse
    {
        $this->authorize('update', $purchaseRequest);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancel($purchaseRequest, $this->user($request), $data['reason']), __('Cancelled.'));
    }

    public function order(Request $request, PurchaseRequest $purchaseRequest, PermissionService $permissions): RedirectResponse
    {
        $this->authorize('view', $purchaseRequest);
        abort_unless($permissions->can($this->user($request), PermissionAction::Create, 'vendor_pos'), 403);
        $data = $request->validate(['vendor_id' => ['required', 'integer', 'exists:vendors,id']]);

        return $this->attempt(fn () => redirect()->route('commercial.vendor-pos.show', $this->service->raisePurchaseOrder($purchaseRequest, (int) $data['vendor_id'], $this->user($request))), __('Vendor PO drafted from the request.'));
    }

    public function receive(Request $request, GoodsReceiptService $receipts): RedirectResponse
    {
        $this->authorize('create', GoodsReceipt::class);
        $data = $request->validate(['vendor_po_id' => ['required', 'integer'], 'received_on' => ['required', 'date', 'before_or_equal:today'], 'kind' => ['required', 'in:goods,service'], 'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'], 'lines.*.po_line_id' => ['required', 'integer'], 'lines.*.quantity_received' => ['required', 'regex:/^\d{1,8}(\.\d{1,4})?$/'], 'lines.*.condition' => ['nullable', 'string', 'max:40']]);

        return $this->attempt(fn () => $receipts->record((int) $data['vendor_po_id'], $data, $this->user($request)), __('Receipt recorded.'));
    }

    /** @return array<string, mixed> */
    public static function row(PurchaseRequest $pr): array
    {
        return ['id' => $pr->id, 'pr_no' => $pr->pr_no, 'title' => $pr->title, 'status' => $pr->status, 'estimated_total' => (string) $pr->estimated_total->getAmount(),
            'needed_by' => $pr->needed_by?->toDateString(), 'vendor' => $pr->vendor?->name, 'vendor_po_id' => $pr->vendor_po_id, 'created_at' => $pr->created_at?->toDateString()];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['title' => ['required', 'string', 'max:255'], 'justification' => ['required', 'string', 'max:5000'], 'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'project_id' => ['nullable', 'integer'], 'activity_id' => ['nullable', 'integer'], 'needed_by' => ['nullable', 'date'],
            'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.description' => ['required', 'string', 'max:500'], 'lines.*.quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,4})?$/'],
            'lines.*.estimated_unit_price' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'lines.*.capitalization_class' => ['nullable', 'in:CAPITAL_ASSET,LOW_VALUE_EQUIPMENT,CONSUMABLE,EXPENSE']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
