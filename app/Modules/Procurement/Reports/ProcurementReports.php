<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Reports;

use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Reports\ReportTable;
use App\Modules\Procurement\Models\PurchaseRequest;
use App\Modules\Procurement\Models\Subscription;
use App\Modules\Procurement\Services\SubscriptionService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * M10 outputs: vendor spending (booked bills), purchase pipeline (PR → PO → receipt → bill), subscription
 * renewal calendar.
 *
 * @see SRS M10 (Output / reporting), M11-05
 */
final class ProcurementReports
{
    public function __construct(private readonly FinanceRecords $finance, private readonly VendorPurchaseOrders $pos, private readonly StaffDirectory $staff) {}

    /** @return array<string, mixed> */
    public function vendorSpending(Carbon $from, Carbon $to): array
    {
        $rows = $this->finance->vendorSpending($from->toDateString(), $to->toDateString());
        $billed = BigDecimal::zero();
        foreach ($rows as $r) {
            $billed = $billed->plus($r['billed']);
        }

        return ReportTable::make('Vendor spending '.$from->toDateString().' – '.$to->toDateString(), ['vendor' => 'Vendor', 'bills' => 'Bills', 'billed' => '#Billed', 'paid' => '#Paid', 'outstanding' => '#Outstanding'],
            array_map(fn (array $r): array => ['vendor' => $r['vendor'], 'bills' => (string) $r['bills'], 'billed' => $r['billed'], 'paid' => $r['paid'], 'outstanding' => $r['outstanding']], $rows),
            ['billed' => (string) $billed->toScale(2)], ['Approved vendor bills only (the ledger impact); POs are commitments and are not counted.']);
    }

    /** @return array<string, mixed> */
    public function pipeline(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach (PurchaseRequest::query()->with('vendor')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->orderByDesc('id')->get() as $pr) {
            $po = $pr->vendor_po_id !== null ? $this->pos->snapshot($pr->vendor_po_id) : null;
            $bills = $pr->vendor_po_id !== null ? $this->finance->billsForVendorPo($pr->vendor_po_id) : [];
            $billed = BigDecimal::zero();
            $paid = BigDecimal::zero();
            foreach ($bills as $b) {
                $billed = $billed->plus($b['amount']);
                $paid = $paid->plus($b['paid']);
            }
            $rows[] = ['pr_no' => $pr->pr_no, 'title' => $pr->title, 'status' => $pr->status, 'estimated' => (string) $pr->estimated_total->getAmount(), 'vendor' => $pr->vendor?->name,
                'po' => $po['number'] ?? ($po !== null ? 'draft PO' : null), 'po_status' => $po['status'] ?? null, 'billed' => (string) $billed->toScale(2), 'paid' => (string) $paid->toScale(2)];
        }

        return ReportTable::make('Purchase pipeline '.$from->toDateString().' – '.$to->toDateString(), ['pr_no' => 'PR', 'title' => 'Title', 'status' => 'PR status', 'estimated' => '#Estimated',
            'vendor' => 'Vendor', 'po' => 'Vendor PO', 'po_status' => 'PO status', 'billed' => '#Billed', 'paid' => '#Paid'], $rows);
    }

    /** @return array<string, mixed> */
    public function renewalCalendar(Carbon $from, Carbon $to): array
    {
        $annual = BigDecimal::zero();
        $rows = [];
        foreach (Subscription::query()->with(['vendor', 'reviews'])->whereIn('status', ['active', 'under_review'])->whereBetween('renewal_date', [$from->toDateString(), $to->toDateString()])->orderBy('renewal_date')->get() as $s) {
            $open = $s->reviews->firstWhere('status', 'open');
            $rows[] = ['renewal_date' => $s->renewal_date->toDateString(), 'days' => (string) SubscriptionService::daysTo($s->renewal_date), 'product' => $s->product, 'vendor' => $s->vendor->name,
                'owner' => $s->license_owner_staff_id ? ($this->staff->snapshot($s->license_owner_staff_id)['name'] ?? null) : null, 'cycle' => $s->billing_cycle, 'price' => (string) $s->price->getAmount(),
                'annualised' => SubscriptionService::annualised($s), 'auto_renew' => $s->auto_renew ? 'yes' : 'no', 'review' => $open !== null ? "open ({$open->alert_level}d)" : '—'];
            $annual = $annual->plus(SubscriptionService::annualised($s));
        }

        return ReportTable::make('Subscription renewal calendar '.$from->toDateString().' – '.$to->toDateString(), ['renewal_date' => 'Renews', 'days' => 'Days', 'product' => 'Product', 'vendor' => 'Vendor',
            'owner' => 'Licence owner', 'cycle' => 'Cycle', 'price' => '#Price', 'annualised' => '#Annualised', 'auto_renew' => 'Auto-renew', 'review' => 'Review'], $rows,
            ['annualised' => (string) $annual->toScale(2)], ['Renewal alerts create review tasks only; money is recorded when the supplier bill or payment is entered in Finance (WF-06).']);
    }
}
