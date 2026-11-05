<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Models\Vendor;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Minimal vendor directory for bills/payments (M10 extends it in Prompt 5). @see SRS M10-01 */
final class VendorController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        return Inertia::render('Procurement/Vendors/Index', [
            'vendors' => Vendor::query()->orderBy('name')->get()->map(fn (Vendor $v): array => ['id' => $v->id, 'name' => $v->name, 'registration_no' => $v->registration_no, 'tax_no' => $v->tax_no, 'bank_reference' => $v->maskedBankReference(), 'status' => $v->status, 'email' => $v->contacts['email'] ?? null, 'phone' => $v->contacts['phone'] ?? null]),
            'can' => ['create' => $request->user()?->can('create', Vendor::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Vendor::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:190'], 'registration_no' => ['nullable', 'string', 'max:40'], 'tax_no' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'], 'bank_reference' => ['nullable', 'string', 'max:60']]);
        Vendor::query()->create(['company_id' => $request->user()?->company_id, 'name' => $data['name'], 'registration_no' => $data['registration_no'] ?? null, 'tax_no' => $data['tax_no'] ?? null, 'contacts' => array_filter(['email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null]), 'bank_reference' => $data['bank_reference'] ?? null, 'status' => 'active']);

        return back()->with('success', __('Vendor added.'));
    }
}
