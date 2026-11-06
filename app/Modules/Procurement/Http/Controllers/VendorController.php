<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Search\SearchIndexer;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendor master (M10-01): registration / tax numbers, contacts, contract documents, bank reference (masked),
 * spending and subscriptions.
 *
 * @see SRS M10-01, M10-03
 */
final class VendorController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        return Inertia::render('Procurement/Vendors/Index', [
            'vendors' => Vendor::query()->orderBy('name')->get()->map(fn (Vendor $v): array => self::row($v)),
            'can' => ['create' => $request->user()?->can('create', Vendor::class) ?? false],
        ]);
    }

    public function store(Request $request, SearchIndexer $search): RedirectResponse
    {
        $this->authorize('create', Vendor::class);
        $data = $request->validate($this->rules());
        $v = Vendor::query()->create($this->fields($data) + ['company_id' => $request->user()?->company_id, 'status' => 'active']);
        $search->index('vendor', $v->id, $v->name, trim(($v->registration_no ?? '').' '.($v->category ?? '')), 'Procurement');

        return redirect()->route('procurement.vendors.show', $v->id)->with('success', __('Vendor added.'));
    }

    public function show(Request $request, Vendor $vendor, DocumentStore $documents, FinanceRecords $finance, PermissionService $permissions): Response
    {
        $this->authorize('view', $vendor);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Procurement/Vendors/Show', [
            'vendor' => self::row($vendor) + ['address' => $vendor->address, 'notes' => $vendor->notes],
            'documents' => $documents->linkedTo('vendor', $vendor->id),
            'spending' => $permissions->can($user, PermissionAction::View, 'bills') ? (collect($finance->vendorSpending('2000-01-01', '2100-12-31'))->firstWhere('vendor_id', $vendor->id) ?? null) : null,
            'subscriptions' => $user->can('viewAny', Subscription::class) ? Subscription::query()->where('vendor_id', $vendor->id)->orderBy('renewal_date')->get()
                ->map(fn (Subscription $s): array => ['id' => $s->id, 'product' => $s->product, 'renewal_date' => $s->renewal_date->toDateString(), 'price' => (string) $s->price->getAmount(), 'billing_cycle' => $s->billing_cycle, 'status' => $s->status]) : [],
            'can' => ['edit' => $user->can('update', $vendor)],
        ]);
    }

    public function update(Request $request, Vendor $vendor, SearchIndexer $search): RedirectResponse
    {
        $this->authorize('update', $vendor);
        $data = $request->validate($this->rules() + ['status' => ['required', 'in:active,inactive']]);
        $fields = $this->fields($data) + ['status' => $data['status']];
        if (($data['bank_reference'] ?? '') === '') {
            unset($fields['bank_reference']); // blank = keep the stored (masked) reference
        }
        $vendor->forceFill($fields)->save();
        $search->index('vendor', $vendor->id, $vendor->name, trim(($vendor->registration_no ?? '').' '.($vendor->category ?? '')), 'Procurement');

        return back()->with('success', __('Saved.'));
    }

    /** @return array<string, mixed> */
    public static function row(Vendor $v): array
    {
        return ['id' => $v->id, 'name' => $v->name, 'registration_no' => $v->registration_no, 'tax_no' => $v->tax_no, 'bank_reference' => $v->maskedBankReference(), 'status' => $v->status,
            'email' => $v->email ?? ($v->contacts['email'] ?? null), 'phone' => $v->phone ?? ($v->contacts['phone'] ?? null), 'category' => $v->category, 'payment_terms_days' => $v->payment_terms_days,
            'contact_person' => $v->contacts['person'] ?? null];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return ['name' => ['required', 'string', 'max:190'], 'registration_no' => ['nullable', 'string', 'max:40'], 'tax_no' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:40'], 'bank_reference' => ['nullable', 'string', 'max:60'], 'category' => ['nullable', 'string', 'max:40'], 'address' => ['nullable', 'string', 'max:1000'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'], 'contact_person' => ['nullable', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:2000']];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        return ['name' => $data['name'], 'registration_no' => $data['registration_no'] ?? null, 'tax_no' => $data['tax_no'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null,
            'contacts' => array_filter(['email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'person' => $data['contact_person'] ?? null]),
            'bank_reference' => $data['bank_reference'] ?? null, 'category' => $data['category'] ?? null, 'address' => $data['address'] ?? null,
            'payment_terms_days' => (int) ($data['payment_terms_days'] ?? 30), 'notes' => $data['notes'] ?? null];
    }
}
