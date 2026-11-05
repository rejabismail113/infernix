<?php

declare(strict_types=1);

use App\Kernel\Notifications\Jobs\NotifyPendingApprovals;
use App\Modules\Commercial\Jobs\NotifyOverdueInvoices;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler (run by the `scheduler` container: php artisan schedule:work). Times are Asia/Kuala_Lumpur.
 * @see SRS G-05
 */
Schedule::job(new NotifyOverdueInvoices)->dailyAt('08:10')->name('notify-overdue-invoices')->withoutOverlapping();
Schedule::job(new NotifyPendingApprovals)->dailyAt('08:20')->name('notify-pending-approvals')->withoutOverlapping();
