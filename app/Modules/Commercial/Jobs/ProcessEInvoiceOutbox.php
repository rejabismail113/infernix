<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Jobs;

use App\Modules\Commercial\EInvoice\EInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Every minute (when features.myinvois is on): submit due outbox rows. @see SRS §6.2 */
final class ProcessEInvoiceOutbox implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(EInvoiceService $service): void
    {
        $service->processDue();
    }
}
