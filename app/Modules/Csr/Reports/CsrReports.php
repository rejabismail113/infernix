<?php

declare(strict_types=1);

namespace App\Modules\Csr\Reports;

use App\Kernel\Reports\ReportTable;
use App\Modules\Csr\Models\CsrProgramme;
use App\Modules\Csr\Services\CsrService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * M08 outputs: CSR spend (cash vs in-kind, never mixed), activities & beneficiaries, and the annual report data.
 *
 * @see SRS M08 (Output / reporting), M08-02, M08-03, M11-05
 */
final class CsrReports
{
    public function __construct(private readonly CsrService $csr) {}

    /** @return array<string, mixed> */
    public function spend(Carbon $from, Carbon $to): array
    {
        $cash = BigDecimal::zero();
        $inKind = BigDecimal::zero();
        $rows = [];
        foreach ($this->programmes($from, $to) as $p) {
            $s = $this->csr->summary($p);
            $rows[] = ['programme_no' => $p->programme_no, 'title' => $p->title, 'date' => $p->programme_date->toDateString(), 'budget' => $s['budget'], 'cash' => $s['cash'], 'in_kind' => $s['in_kind']];
            $cash = $cash->plus($s['cash']);
            $inKind = $inKind->plus($s['in_kind']);
        }

        return ReportTable::make('CSR spend '.$from->toDateString().' – '.$to->toDateString(), ['programme_no' => 'Programme', 'title' => 'Title', 'date' => 'Date', 'budget' => '#Budget',
            'cash' => '#Cash spend (finance)', 'in_kind' => '#In-kind (estimated)'], $rows, ['cash' => (string) $cash->toScale(2), 'in_kind' => (string) $inKind->toScale(2)],
            ['Cash spend is the finance records linked through the programme activity. In-kind values are estimates, reported separately and never added to cash (M08-03).']);
    }

    /** @return array<string, mixed> */
    public function beneficiaries(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach ($this->programmes($from, $to) as $p) {
            $s = $this->csr->summary($p);
            $rows[] = ['programme_no' => $p->programme_no, 'title' => $p->title, 'partner' => $p->partner, 'venue' => $p->venue, 'beneficiary_group' => $p->beneficiary_group,
                'target' => (string) $p->target_count, 'actual' => $p->actual_count === null ? null : (string) $p->actual_count, 'volunteers' => (string) $p->volunteers->count(), 'hours' => $s['volunteer_hours'], 'status' => $p->status];
        }

        return ReportTable::make('CSR activities & beneficiaries '.$from->toDateString().' – '.$to->toDateString(), ['programme_no' => 'Programme', 'title' => 'Title', 'partner' => 'Partner', 'venue' => 'Venue',
            'beneficiary_group' => 'Beneficiaries', 'target' => 'Target', 'actual' => 'Reached', 'volunteers' => 'Volunteers', 'hours' => 'Volunteer hours', 'status' => 'Status'], $rows);
    }

    /** @return array{year: int, programmes: list<array<string, mixed>>, totals: array{cash: string, in_kind: string, reached: int, hours: string, programmes: int}} */
    public function annual(int $year): array
    {
        $from = Carbon::create($year, 1, 1) ?? today();
        $to = $from->copy()->endOfYear();
        $cash = BigDecimal::zero();
        $inKind = BigDecimal::zero();
        $hours = BigDecimal::zero();
        $reached = 0;
        $out = [];
        foreach ($this->programmes($from, $to) as $p) {
            $s = $this->csr->summary($p);
            $out[] = ['programme_no' => $p->programme_no, 'title' => $p->title, 'date' => $p->programme_date->toDateString(), 'objectives' => $p->objectives, 'partner' => $p->partner, 'sponsor' => $p->sponsor,
                'venue' => $p->venue, 'beneficiary_group' => $p->beneficiary_group, 'target' => $p->target_count, 'actual' => $p->actual_count, 'outcome' => $p->outcome, 'status' => $p->status] + $s;
            $cash = $cash->plus($s['cash']);
            $inKind = $inKind->plus($s['in_kind']);
            $hours = $hours->plus($s['volunteer_hours']);
            $reached += (int) $p->actual_count;
        }

        return ['year' => $year, 'programmes' => $out, 'totals' => ['cash' => (string) $cash->toScale(2), 'in_kind' => (string) $inKind->toScale(2), 'reached' => $reached, 'hours' => (string) $hours->toScale(2), 'programmes' => count($out)]];
    }

    /** @return Collection<int, CsrProgramme> */
    private function programmes(Carbon $from, Carbon $to): Collection
    {
        return CsrProgramme::query()->with('volunteers')->whereBetween('programme_date', [$from->toDateString(), $to->toDateString()])->where('status', '!=', 'cancelled')->orderBy('programme_date')->get();
    }
}
