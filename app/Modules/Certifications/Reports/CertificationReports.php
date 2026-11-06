<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Reports;

use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Reports\ReportTable;
use App\Modules\Certifications\Models\Certification;
use App\Modules\Certifications\Models\Renewal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * M09 outputs: expiring certificates / memberships and renewal expenditure (from linked finance records).
 *
 * @see SRS M09 (Output / reporting), M09-04, M11-05
 */
final class CertificationReports
{
    public function __construct(private readonly FinanceRecords $finance) {}

    /** @return array<string, mixed> */
    public function expiring(Carbon $from, Carbon $to): array
    {
        $rows = Renewal::query()->where('status', 'open')->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->orderBy('due_date')->get()
            ->map(function (Renewal $r): ?array {
                $item = $r->renewable();
                if ($item === null) {
                    return null;
                }

                return ['type' => $r->renewable_type, 'name' => $item instanceof Certification ? "{$item->name} ({$item->issuer})" : "{$item->organisation} membership",
                    'holder' => $item->holderLabel(), 'reference' => $item instanceof Certification ? $item->credential_no : $item->membership_no,
                    'due_date' => $r->due_date->toDateString(), 'days' => (string) today()->diffInDays($r->due_date, false)];
            })->filter()->values()->all();

        return ReportTable::make('Expiring certificates & memberships '.$from->toDateString().' – '.$to->toDateString(),
            ['type' => 'Type', 'name' => 'Name', 'holder' => 'Holder', 'reference' => 'Credential / membership no', 'due_date' => 'Expires', 'days' => 'Days left'], $rows, [],
            ['Certificates with no expiry are not listed (M09-03).']);
    }

    /** @return array<string, mixed> */
    public function expenditure(Carbon $from, Carbon $to): array
    {
        $renewals = Renewal::query()->whereIn('status', ['renewed', 'open'])->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->orderBy('due_date')->get();
        $totals = $this->finance->linkedTotals('renewal', $renewals->pluck('id')->all(), 'renewal');
        $sum = BigDecimal::zero();
        $rows = [];
        foreach ($renewals as $r) {
            $item = $r->renewable();
            $rows[] = ['type' => $r->renewable_type, 'name' => $item instanceof Certification ? $item->name : ($item->organisation ?? '—'), 'holder' => $item?->holderLabel(),
                'due_date' => $r->due_date->toDateString(), 'status' => $r->status, 'paid' => $totals[$r->id] ?? '0.00',
                'records' => implode(', ', array_map(fn (array $l): string => $l['number'], $this->finance->linked('renewal', $r->id)))];
            $sum = $sum->plus($totals[$r->id] ?? '0');
        }

        return ReportTable::make('Renewal expenditure '.$from->toDateString().' – '.$to->toDateString(), ['type' => 'Type', 'name' => 'Name', 'holder' => 'Holder', 'due_date' => 'Due', 'status' => 'Status',
            'paid' => '#Paid (linked finance)', 'records' => 'Finance records'], $rows, ['paid' => (string) $sum->toScale(2)], ['Amounts are the finance records linked to each renewal; renewals never create an expense (M09-04).']);
    }
}
