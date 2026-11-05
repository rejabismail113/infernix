<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\CompanyBankAccount;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-03, M03-08 */
final class CompanyController
{
    use AuthorizesRequests;

    public function edit(Request $request): Response
    {
        $company = Company::current();
        $this->authorize('view', $company);

        return Inertia::render('Admin/Company/Edit', [
            'company' => $company->only(['id', 'name', 'ssm_no', 'address', 'phone', 'email', 'signatory_name', 'signatory_title', 'base_currency', 'timezone']),
            'bankAccounts' => $company->bankAccounts()->orderBy('id')->get()
                ->map(fn (CompanyBankAccount $b): array => ['id' => $b->id, 'bank_name' => $b->bank_name, 'account_no_masked' => $b->maskedAccountNo(), 'account_name' => $b->account_name, 'is_default' => $b->is_default, 'active' => $b->active]),
            'can' => ['edit' => $request->user()?->can('update', $company) ?? false],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $company = Company::current();
        $this->authorize('update', $company);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'ssm_no' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'array'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'signatory_name' => ['nullable', 'string', 'max:120'],
            'signatory_title' => ['nullable', 'string', 'max:120'],
        ]);
        $company->fill($data)->save();

        return back()->with('success', __('Company profile updated.'));
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $company = Company::current();
        $this->authorize('update', $company);
        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_no' => ['required', 'string', 'max:40'],
            'account_name' => ['required', 'string', 'max:190'],
            'is_default' => ['boolean'],
        ]);
        if ($data['is_default'] ?? false) {
            $company->bankAccounts()->update(['is_default' => false]);
        }
        $company->bankAccounts()->create($data);

        return back()->with('success', __('Bank account added.'));
    }
}
