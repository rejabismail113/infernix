<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Reports;

use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use App\Modules\HumanResources\Models\PaymentBatchLine;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Models\TimesheetEntry;
use Illuminate\Support\Carbon;

/**
 * M04 outputs: staff roster, timesheet summary, part-time payment history. Identifiers appear MASKED only;
 * bank details never appear (M04-06).
 *
 * @see SRS M04 (Output / reporting), M04-06, M11-05
 */
final class HrReports
{
    /** @return array<string, mixed> */
    public function roster(): array
    {
        return ['title' => 'Staff roster',
            'columns' => [['key' => 'staff_no', 'label' => 'Staff no'], ['key' => 'name', 'label' => 'Name'], ['key' => 'id_masked', 'label' => 'NRIC / passport'], ['key' => 'engagement_type', 'label' => 'Engagement'], ['key' => 'classification', 'label' => 'Classification'],
                ['key' => 'position', 'label' => 'Position'], ['key' => 'joined_on', 'label' => 'Joined'], ['key' => 'ended_on', 'label' => 'Ended'], ['key' => 'status', 'label' => 'Status']],
            'rows' => StaffProfile::query()->orderBy('legal_name')->get()->map(fn (StaffProfile $s): array => ['staff_no' => $s->staff_no, 'name' => $s->legal_name, 'id_masked' => $s->maskedId(), 'engagement_type' => $s->engagement_type,
                'classification' => $s->classification, 'position' => $s->position, 'joined_on' => $s->joined_on?->toDateString(), 'ended_on' => $s->ended_on?->toDateString(), 'status' => $s->status])->values()->all(),
            'totals' => [], 'notes' => ['Identification numbers are masked. Full numbers are revealed only on the staff profile, with an audited reason.']];
    }

    public function __construct(private readonly PermissionService $permissions, private readonly ProjectDirectory $projects) {}

    /** @return array<string, mixed> */
    public function timesheetSummary(Carbon $from, Carbon $to, ?User $user = null): array
    {
        $scope = $user ? $this->permissions->scopeFor($user, 'timesheets', PermissionAction::View) : RecordScope::All;
        $managed = $user && $scope === RecordScope::Assigned ? $this->projects->managedProjectIds($user) : [];
        $rows = Timesheet::query()->with('staff')->whereDate('period_start', '>=', $from)->whereDate('period_end', '<=', $to)
            ->when($scope !== RecordScope::All, fn ($q) => $q->where(fn ($w) => $w->whereHas('staff', fn ($s) => $s->where('user_id', $user->id ?? 0))
                ->orWhereIn('id', TimesheetEntry::query()->whereIn('project_id', $managed === [] ? [0] : $managed)->select('timesheet_id'))))
            ->orderBy('period_start')->get()
            ->map(fn (Timesheet $t): array => ['staff' => $t->staff->displayName(), 'period' => $t->period_start->toDateString().' – '.$t->period_end->toDateString(), 'status' => $t->status->value,
                'quantity' => $t->total_quantity, 'rate_type' => $t->rate_type, 'rate' => $t->rate_snapshot !== null ? (string) $t->rate_snapshot->getAmount() : null,
                'amount' => $t->calculated_amount !== null ? (string) $t->calculated_amount->getAmount() : null])->values()->all();
        $total = collect($rows)->reduce(fn ($c, $r) => $r['amount'] !== null ? $c->plus(Money::myr($r['amount'])) : $c, Money::zero());

        return ['title' => 'Timesheet summary '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'staff', 'label' => 'Staff'], ['key' => 'period', 'label' => 'Period'], ['key' => 'status', 'label' => 'Status'], ['key' => 'quantity', 'label' => 'Quantity'], ['key' => 'rate_type', 'label' => 'Rate type'], ['key' => 'rate', 'label' => 'Rate', 'numeric' => true], ['key' => 'amount', 'label' => 'Amount', 'numeric' => true]],
            'rows' => $rows, 'totals' => ['calculated' => (string) $total->getAmount()], 'notes' => ['Amounts use the rate version locked at calculation (WF-05).']];
    }

    /** @return array<string, mixed> */
    public function paymentHistory(Carbon $from, Carbon $to): array
    {
        $rows = PaymentBatchLine::query()->join('payment_batches as b', 'b.id', '=', 'payment_batch_lines.payment_batch_id')->where('b.status', 'paid')->whereBetween('b.paid_on', [$from->toDateString(), $to->toDateString()])
            ->with('staff')->orderBy('b.paid_on')->get(['payment_batch_lines.*', 'b.batch_no', 'b.paid_on', 'b.voucher_no', 'b.payout_method'])
            ->map(fn (PaymentBatchLine $l): array => ['paid_on' => (string) $l->getAttribute('paid_on'), 'batch_no' => (string) $l->getAttribute('batch_no'), 'voucher_no' => $l->getAttribute('voucher_no'), 'staff' => $l->staff->displayName(),
                'staff_no' => $l->staff->staff_no, 'classification' => $l->classification, 'method' => $l->getAttribute('payout_method'), 'amount' => (string) $l->amount->getAmount()])->values()->all();
        $total = collect($rows)->reduce(fn ($c, $r) => $c->plus(Money::myr($r['amount'])), Money::zero());

        return ['title' => 'Part-time payment history '.$from->toDateString().' – '.$to->toDateString(),
            'columns' => [['key' => 'paid_on', 'label' => 'Paid'], ['key' => 'batch_no', 'label' => 'Batch'], ['key' => 'voucher_no', 'label' => 'Voucher'], ['key' => 'staff_no', 'label' => 'Staff no'], ['key' => 'staff', 'label' => 'Staff'], ['key' => 'classification', 'label' => 'Classification'], ['key' => 'method', 'label' => 'Method'], ['key' => 'amount', 'label' => 'Amount', 'numeric' => true]],
            'rows' => $rows, 'totals' => ['paid' => (string) $total->getAmount()], 'notes' => ['Bank details are never included in reports.']];
    }
}
