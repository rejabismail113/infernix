<?php

declare(strict_types=1);

namespace App\Modules\Administration\Database\Seeders;

use App\Kernel\Numbering\Models\NumberingSequence;
use App\Modules\Administration\Models\Company;
use Illuminate\Database\Seeder;

/** QT-2026-0001 style sequences for every document type (docs/DECISIONS.md D-27). @see SRS §6.3, M03-08 */
final class NumberingSequenceSeeder extends Seeder
{
    /** @var array<string, string> */
    public const TYPES = [
        'QUOTATION' => 'QT', 'INVOICE' => 'INV', 'RECEIPT' => 'RC', 'PAYMENT_VOUCHER' => 'PV', 'CREDIT_NOTE' => 'CN',
        'VENDOR_PO' => 'PO', 'CLIENT_PO' => 'CPO', 'JOURNAL' => 'JV', 'CLAIM' => 'CL', 'BILL' => 'BL', 'PURCHASE_REQUEST' => 'PR',
        'MEMO' => 'MEMO', 'LETTER' => 'LTR', 'CERTIFICATE' => 'CERT', 'ASSET' => 'AST', 'PAYMENT_BATCH' => 'PB', 'TRANSACTION' => 'TX', 'PROJECT' => 'PRJ', 'STAFF' => 'ST', 'APPOINTMENT_LETTER' => 'APL',
    ];

    public function run(): void
    {
        $company = Company::current();
        foreach ([2026, 2027] as $year) {
            foreach (self::TYPES as $type => $prefix) {
                NumberingSequence::withoutEvents(fn () => NumberingSequence::query()->firstOrCreate(
                    ['company_id' => $company->id, 'document_type' => $type, 'fiscal_year' => $year],
                    ['prefix' => $prefix, 'format' => '{prefix}-{year}-{seq}', 'next_number' => 1, 'padding' => 4],
                ));
            }
        }
    }
}
