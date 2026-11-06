<?php

declare(strict_types=1);

namespace App\Modules\HumanResources;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchIndexer;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Commercial\Events\DocumentIssued;
use App\Modules\HumanResources\Listeners\DeliverMemoToInbox;
use App\Modules\HumanResources\Models\Engagement;
use App\Modules\HumanResources\Models\PaymentBatch;
use App\Modules\HumanResources\Models\PaymentBatchLine;
use App\Modules\HumanResources\Models\StaffBankDetail;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\HumanResources\Models\Timesheet;
use App\Modules\HumanResources\Policies\EngagementPolicy;
use App\Modules\HumanResources\Policies\PaymentBatchPolicy;
use App\Modules\HumanResources\Policies\StaffBankDetailPolicy;
use App\Modules\HumanResources\Policies\StaffProfilePolicy;
use App\Modules\HumanResources\Policies\TimesheetPolicy;
use App\Modules\HumanResources\Reports\HrReports;
use App\Modules\HumanResources\Services\StaffDirectoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M04 — HR & Part-time Staff.
 *
 * @see SRS §4 M04
 * @see docs/ARCHITECTURE.md
 */
final class HumanResourcesServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M04';

    public function register(): void
    {
        $this->app->bind(StaffDirectory::class, StaffDirectoryService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        foreach ([StaffProfile::class => StaffProfilePolicy::class, StaffBankDetail::class => StaffBankDetailPolicy::class, Engagement::class => EngagementPolicy::class,
            Timesheet::class => TimesheetPolicy::class, PaymentBatch::class => PaymentBatchPolicy::class] as $model => $policy) {
            Gate::policy($model, $policy);
        }
        $can = fn (User $u, PermissionAction $a, string $r): bool => app(PermissionService::class)->canOnAll($u, $a, $r);
        Gate::define('reveal-staff-identifier', fn (User $u): bool => $can($u, PermissionAction::Reveal, 'staff_identifiers'));
        Gate::define('reveal-staff-bank', fn (User $u): bool => $can($u, PermissionAction::Reveal, 'staff_bank'));
        // Global search (G-11): name, staff no and position only — never the identity number or bank details.
        $this->app->make(SearchRegistry::class)->register('staff', StaffProfile::class, 'Staff', fn (Model $s): string => route('hr.staff.show', $s->getKey()));
        StaffProfile::saved(fn (StaffProfile $s) => app(SearchIndexer::class)->index('staff', $s->id, trim($s->staff_no.' '.$s->displayName()), $s->position, 'HR'));
        Gate::define('edit-staff-bank', fn (User $u): bool => $can($u, PermissionAction::Edit, 'staff_bank'));

        Event::listen(DocumentIssued::class, DeliverMemoToInbox::class);

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->toDateString())];
        $catalog->register(new ReportDefinition('hr.roster', 'Staff roster', 'HR', 'staff_profiles', fn (array $f): array => app(HrReports::class)->roster(), []));
        $catalog->register(new ReportDefinition('hr.timesheet-summary', 'Timesheet summary', 'HR', 'timesheets', fn (array $f, ?User $u = null): array => app(HrReports::class)->timesheetSummary(...[...$range($f), $u])));
        $catalog->register(new ReportDefinition('hr.payment-history', 'Part-time payment history', 'HR', 'payment_batches', fn (array $f): array => app(HrReports::class)->paymentHistory(...$range($f))));

        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('staff_profile', StaffProfile::class, 'Staff profile', ['contract', 'nda', 'agreement', 'certificate', 'memo', 'appointment', 'evidence', 'attachment', 'other'],
            ownedIds: fn (User $u): array => StaffProfile::query()->where('user_id', $u->id)->pluck('id')->all(),
            labelFor: fn (StaffProfile $s): string => "{$s->staff_no} {$s->displayName()}"));
        $registry->register(new LinkableEntityType('payment_batch', PaymentBatch::class, 'Payment batch', ['issued_document', 'evidence', 'attachment'], labelFor: fn (PaymentBatch $b): string => $b->batch_no));
        $registry->register(new LinkableEntityType('payment_batch_line', PaymentBatchLine::class, 'Payment batch line', ['invoice', 'evidence'], labelFor: fn (PaymentBatchLine $l): string => "batch line #{$l->id}"));

        $this->app->make(PendingApprovalRegistry::class)->register('hr', function (): array {
            $items = [];
            foreach (PaymentBatch::query()->where('status', 'pending_approval')->limit(200)->get() as $b) {
                $items[] = ['key' => "payment_batch:{$b->id}:L{$b->current_level}", 'title' => "Payment batch {$b->batch_no} (RM {$b->total_amount->getAmount()})", 'url' => route('hr.batches.show', $b->id),
                    'roles' => app(ApprovalService::class)->rolesFor('payment_batch', $b->total_amount, $b->current_level), 'preparer_id' => $b->created_by];
            }

            return $items;
        });
    }
}
