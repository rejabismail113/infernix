<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Jobs;

use App\Kernel\Money\Money;
use App\Kernel\Notifications\NotificationService;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\SettlementStatus;
use App\Modules\Commercial\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily: remind Finance/Owner of invoices past their due date (rule InvoiceOverdue, escalating at the
 * configured lead days — PLACEHOLDER 1 / 14 / 30 days).
 *
 * @see SRS G-05, M03-03, WF-04
 */
final class NotifyOverdueInvoices implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(NotificationService $notifications): void
    {
        Invoice::query()->whereIn('status', [DocumentStatus::Issued->value, DocumentStatus::Accepted->value])
            ->where('settlement_status', '!=', SettlementStatus::Paid->value)->whereDate('due_date', '<', today())
            ->orderBy('due_date')->chunk(200, function ($invoices) use ($notifications): void {
                foreach ($invoices as $inv) {
                    /** @var Invoice $inv */
                    $days = (int) $inv->due_date?->diffInDays(today());
                    $out = Money::format($inv->outstanding());
                    $notifications->notify('InvoiceOverdue', $days, "invoice:{$inv->id}",
                        "Invoice {$inv->document_no} is {$days} days overdue",
                        "{$inv->counterpartyName()} owes RM {$out} on {$inv->document_no} (due {$inv->due_date?->toDateString()}).",
                        route('commercial.invoices.show', $inv->id));
                }
            });
    }
}
