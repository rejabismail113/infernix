<?php

declare(strict_types=1);

use App\Kernel\Notifications\Jobs\NotifyPendingApprovals;
use App\Modules\Assets\Jobs\NotifyWarrantyExpiries;
use App\Modules\Certifications\Jobs\NotifyRenewals;
use App\Modules\Commercial\Jobs\NotifyOverdueInvoices;
use App\Modules\Commercial\Jobs\ProcessEInvoiceOutbox;
use App\Modules\Credentials\Jobs\NotifyCredentialRotations;
use App\Modules\Procurement\Jobs\RunSubscriptionRenewalAlerts;
use App\Modules\Reporting\Jobs\SendScheduledReports;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduler (run by the `scheduler` container: php artisan schedule:work). Times are Asia/Kuala_Lumpur.
 * @see SRS G-05
 */
Schedule::job(new NotifyOverdueInvoices)->dailyAt('08:10')->name('notify-overdue-invoices')->withoutOverlapping();
Schedule::job(new NotifyPendingApprovals)->dailyAt('08:20')->name('notify-pending-approvals')->withoutOverlapping();
Schedule::job(new RunSubscriptionRenewalAlerts)->dailyAt('07:40')->name('subscription-renewal-alerts')->withoutOverlapping();
Schedule::job(new NotifyRenewals)->dailyAt('07:50')->name('certification-renewals')->withoutOverlapping();
Schedule::job(new NotifyWarrantyExpiries)->dailyAt('08:00')->name('warranty-expiries')->withoutOverlapping();
Schedule::job(new NotifyCredentialRotations)->dailyAt('08:30')->name('credential-rotations')->withoutOverlapping();
Schedule::job(new SendScheduledReports)->dailyAt('06:50')->name('scheduled-reports')->withoutOverlapping();
Schedule::job(new ProcessEInvoiceOutbox)->everyMinute()->name('einvoice-outbox')->withoutOverlapping()->when(fn () => (bool) config('infernix.features.myinvois'));
// NFR-06 / M12-06: nightly encrypted backup (+ retention prune), quarterly restore test into a scratch DB.
Schedule::command('backup:run backup')->dailyAt('01:30')->name('backup-nightly')->withoutOverlapping()->onOneServer();
Schedule::command('backup:run restore-test')->cron('30 3 1 */3 *')->name('backup-restore-test')->withoutOverlapping()->onOneServer();
