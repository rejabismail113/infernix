<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Modules\Administration\Models\Company;
use App\Modules\Administration\Models\CompanyBankAccount;

/**
 * Infernix branding pulled from the company profile at ISSUE time and stored on the document as a snapshot,
 * so later profile changes never alter an issued document (M03-07, M03-08).
 *
 * @see SRS M03-07, M03-08, G-03
 */
final class IssuerProfile
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $company = Company::current();
        $bank = CompanyBankAccount::query()->where('company_id', $company->id)->where('active', true)->orderByDesc('is_default')->orderBy('id')->first();
        $logo = public_path('images/infernix-logo.svg');

        return [
            'name' => $company->name,
            'ssm_no' => $company->ssm_no,
            'address' => $company->address,
            'phone' => $company->phone ?? null,
            'email' => $company->email ?? null,
            'signatory_name' => $company->signatory_name,
            'signatory_title' => $company->signatory_title,
            // The company's own receiving account is printed on invoices so clients can pay; it is not a secret.
            'bank' => $bank === null ? null : ['bank_name' => $bank->bank_name, 'account_name' => $bank->account_name, 'account_no' => $bank->account_no],
            'logo' => ['source' => 'images/infernix-logo.svg', 'sha256' => is_file($logo) ? hash_file('sha256', $logo) : null],
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
