<?php

declare(strict_types=1);

namespace App\Modules\Assets\Http\Controllers;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Crm\Contracts\VendorDirectory;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetAssignment;
use App\Modules\Assets\Models\AssetCandidate;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Assets\Models\AssetDepreciation;
use App\Modules\Assets\Models\AssetDisposal;
use App\Modules\Assets\Models\AssetMaintenance;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Assets\Services\DepreciationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asset register (M05): list + pending registrations from posted capital purchases, detail with custodian
 * history, maintenance (repair cost linked to finance), depreciation schedule and disposals.
 *
 * @see SRS M05-01, M05-02, M05-03, M05-04, M05-05, UAT-03
 */
final class AssetController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function __construct(private readonly AssetService $service, private readonly StaffDirectory $staff) {}

    public function index(Request $request, FinanceRecords $finance): Response
    {
        $this->authorize('viewAny', Asset::class);
        $user = $this->user($request);
        $f = $request->only(['q', 'status', 'class']);
        $mineOnly = app(PermissionService::class)->scopeFor($user, 'assets', PermissionAction::View) !== RecordScope::All;
        $assets = Asset::query()->with('category')
            ->when($mineOnly, fn ($q) => $q->where('custodian_staff_id', $this->staff->idForUser($user) ?? 0))
            ->when(! empty($f['q']), fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$f['q'].'%')->orWhere('asset_no', 'ilike', '%'.$f['q'].'%')->orWhere('serial_no', 'ilike', '%'.$f['q'].'%')))
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(! empty($f['class']), fn ($q) => $q->where('asset_class', $f['class']))
            ->orderByDesc('id')->limit(500)->get();
        $canCreate = $user->can('create', Asset::class);

        return Inertia::render('Assets/Index', [
            'assets' => $assets->map(fn (Asset $a): array => $this->row($a))->values(),
            'candidates' => $canCreate ? AssetCandidate::query()->where('status', 'pending')->orderBy('purchase_date')->get()->map(fn (AssetCandidate $c): array => [
                'id' => $c->id, 'source_type' => $c->source_type, 'source' => $finance->snapshot($c->source_type, $c->source_id), 'description' => $c->description,
                'amount' => (string) $c->amount->getAmount(), 'registered' => (string) Money::fromDb((string) (Asset::query()->where('purchase_source_type', $c->source_type)->where('purchase_line_id', $c->source_line_id)->sum('cost') ?: '0'))->getAmount(),
                'purchase_date' => $c->purchase_date->toDateString()])->values() : [],
            'categories' => AssetCategory::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'default_class', 'useful_life_months', 'residual_percent']),
            'policy' => ['capital_threshold' => (string) config('infernix.assets.capital_threshold'), 'low_value_threshold' => (string) config('infernix.assets.low_value_threshold'), 'posting' => DepreciationService::postingEnabled()],
            'filters' => $f,
            'can' => ['create' => $canCreate],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Asset::class);
        $data = $request->validate($this->rules() + ['acquisition_type' => ['required', 'in:opening_balance,donation'], 'acquired_on' => ['required', 'date'], 'cost' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => redirect()->route('assets.assets.show', $this->service->registerManual($data, $this->user($request))->id), __('Asset registered.'));
    }

    public function register(Request $request, AssetCandidate $candidate): RedirectResponse
    {
        $this->authorize('create', Asset::class);
        $data = $request->validate($this->rules() + ['cost' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => redirect()->route('assets.assets.show', $this->service->registerFromCandidate($candidate, $data, $this->user($request))->id), __('Asset registered from the purchase (no new expense).'));
    }

    public function dismiss(Request $request, AssetCandidate $candidate): RedirectResponse
    {
        $this->authorize('create', Asset::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->service->dismissCandidate($candidate, $data['reason'], $this->user($request)), __('Dismissed.'));
    }

    public function show(Request $request, Asset $asset, DocumentStore $documents, FinanceRecords $finance, VendorDirectory $vendors, ProjectDirectory $projects): Response
    {
        $this->authorize('view', $asset);
        $user = $this->user($request);
        $asset->load('category', 'assignments', 'maintenance', 'depreciation', 'disposals');
        $canEdit = $user->can('update', $asset);
        $purchase = $asset->purchaseRef();

        return Inertia::render('Assets/Show', [
            'asset' => $this->row($asset) + ['description' => $asset->description, 'model' => $asset->model, 'acquisition_type' => $asset->acquisition_type, 'residual_value' => (string) $asset->residual_value->getAmount(),
                'useful_life_months' => $asset->useful_life_months, 'depreciation_method' => $asset->depreciation_method, 'notes' => $asset->notes, 'vendor_id' => $asset->vendor_id, 'project_id' => $asset->project_id, 'category_id' => $asset->category_id],
            'purchase' => $purchase === null ? null : $finance->snapshot($purchase['type'], $purchase['id']),
            'assignments' => $asset->assignments->map(fn (AssetAssignment $x): array => ['id' => $x->id, 'custodian' => $x->staff_profile_id ? ($this->staff->snapshot($x->staff_profile_id)['name'] ?? '—') : null, 'location' => $x->location,
                'assigned_on' => $x->assigned_on->toDateString(), 'expected_return_on' => $x->expected_return_on?->toDateString(), 'returned_on' => $x->returned_on?->toDateString(), 'condition_out' => $x->condition_out, 'condition_in' => $x->condition_in, 'note' => $x->note])->values(),
            'maintenance' => $asset->maintenance->map(fn (AssetMaintenance $m): array => ['id' => $m->id, 'kind' => $m->kind, 'reported_on' => $m->reported_on->toDateString(), 'completed_on' => $m->completed_on?->toDateString(),
                'description' => $m->description, 'status' => $m->status, 'estimated_cost' => $m->estimated_cost !== null ? (string) $m->estimated_cost->getAmount() : null, 'vendor' => $m->vendor_id ? ($vendors->snapshot($m->vendor_id)['name'] ?? null) : null,
                'costs' => $finance->linked('asset_maintenance', $m->id)])->values(),
            'depreciation' => $asset->depreciation->map(fn (AssetDepreciation $d): array => ['period_end' => $d->period_end->toDateString(), 'amount' => (string) $d->amount->getAmount(), 'accumulated' => (string) $d->accumulated->getAmount(), 'nbv' => (string) $d->nbv->getAmount(), 'posted' => $d->journal_id !== null])->values(),
            'disposals' => $asset->disposals->map(fn (AssetDisposal $d): array => DisposalController::row($d, $finance))->values(),
            'documents' => $documents->linkedTo('asset', $asset->id),
            'staff' => $canEdit ? $this->staff->options($user) : [],
            'vendors' => $canEdit ? $vendors->options($user) : [],
            'projects' => $canEdit ? $projects->options($user) : [],
            'categories' => AssetCategory::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'postingEnabled' => DepreciationService::postingEnabled(),
            'can' => ['edit' => $canEdit && $asset->status !== 'disposed', 'dispose' => $user->can('create', AssetDisposal::class) && ! in_array($asset->status, ['disposed', 'lost'], true),
                'approve' => $user->can('approve', AssetDisposal::class), 'link_finance' => $canEdit && app(PermissionService::class)->can($user, PermissionAction::View, 'transactions')],
        ]);
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);
        $data = $request->validate($this->rules() + ['asset_class' => ['sometimes', 'in:capital_asset,low_value_equipment']]);

        return $this->attempt(fn () => $this->service->update($asset, $data, $this->user($request)), __('Saved.'));
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);
        $data = $request->validate(['staff_profile_id' => ['nullable', 'integer'], 'location' => ['nullable', 'string', 'max:255'], 'assigned_on' => ['required', 'date'],
            'expected_return_on' => ['nullable', 'date', 'after_or_equal:assigned_on'], 'condition_out' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->assign($asset, $data, $this->user($request)), __('Assigned.'));
    }

    public function returnAsset(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);
        $data = $request->validate(['returned_on' => ['required', 'date'], 'condition_in' => ['nullable', 'string', 'max:255'], 'note' => ['nullable', 'string', 'max:1000'], 'location' => ['nullable', 'string', 'max:255']]);

        return $this->attempt(fn () => $this->service->returnAsset($asset, $data, $this->user($request)), __('Return recorded.'));
    }

    public function maintenance(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorize('update', $asset);
        $data = $request->validate(['kind' => ['required', 'in:repair,service,inspection,upgrade'], 'reported_on' => ['required', 'date'], 'description' => ['required', 'string', 'max:2000'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'], 'estimated_cost' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/']]);

        return $this->attempt(fn () => $this->service->logMaintenance($asset, $data, $this->user($request)), __('Maintenance logged.'));
    }

    public function completeMaintenance(Request $request, AssetMaintenance $maintenance): RedirectResponse
    {
        $this->authorize('update', Asset::query()->findOrFail($maintenance->asset_id));
        $data = $request->validate(['completed_on' => ['required', 'date']]);

        return $this->attempt(fn () => $this->service->completeMaintenance($maintenance, $data['completed_on'], $this->user($request)), __('Completed.'));
    }

    public function linkRepairCost(Request $request, AssetMaintenance $maintenance): RedirectResponse
    {
        $this->authorize('update', Asset::query()->findOrFail($maintenance->asset_id));
        $data = $request->validate(['source_type' => ['required', 'in:transaction,bill,claim'], 'source_id' => ['required', 'integer']]);

        return $this->attempt(fn () => $this->service->linkRepairCost($maintenance, $data['source_type'], (int) $data['source_id'], $this->user($request)), __('Repair cost linked.'));
    }

    /** @return array<string, mixed> */
    private function row(Asset $a): array
    {
        return ['id' => $a->id, 'asset_no' => $a->asset_no, 'name' => $a->name, 'category' => $a->category?->name, 'asset_class' => $a->asset_class, 'serial_no' => $a->serial_no,
            'status' => $a->status, 'location' => $a->location, 'acquired_on' => $a->acquired_on->toDateString(), 'cost' => (string) $a->cost->getAmount(),
            'warranty_expires_on' => $a->warranty_expires_on?->toDateString(), 'custodian' => $a->custodian_staff_id ? ($this->staff->snapshot($a->custodian_staff_id)['name'] ?? null) : null,
            'source' => $a->purchase_source_type ?? $a->acquisition_type];
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'category_id' => ['nullable', 'integer', 'exists:asset_categories,id'],
            'serial_no' => ['nullable', 'string', 'max:120'], 'model' => ['nullable', 'string', 'max:120'], 'location' => ['nullable', 'string', 'max:255'],
            'warranty_expires_on' => ['nullable', 'date'], 'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'], 'residual_value' => ['nullable', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'], 'project_id' => ['nullable', 'integer'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
