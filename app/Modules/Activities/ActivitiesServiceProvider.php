<?php

declare(strict_types=1);

namespace App\Modules\Activities;

use App\Kernel\Crm\Contracts\ActivityDirectory;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Activities\Models\Activity;
use App\Modules\Activities\Policies\ActivityPolicy;
use App\Modules\Activities\Reports\ActivityReports;
use App\Modules\Activities\Services\ActivityService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M07 — Corporate Activities & Travel.
 *
 * @see SRS §4 M07
 * @see docs/ARCHITECTURE.md
 */
final class ActivitiesServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M07';

    public function register(): void
    {
        $this->app->bind(ActivityDirectory::class, ActivityService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Activity::class, ActivityPolicy::class);

        $this->app->make(DocumentLinkRegistry::class)->register(new LinkableEntityType('activity', Activity::class, 'Activity', ['photo', 'report', 'itinerary', 'evidence', 'attachment', 'other'],
            accessibleIds: fn (User $u): array => app(ActivityService::class)->visibleQuery($u)->pluck('id')->all(),
            labelFor: fn (Activity $a): string => "{$a->activity_no} {$a->title}"));
        $this->app->make(SearchRegistry::class)->register('activity', Activity::class, 'Activity', fn (Model $a): string => route('activities.activities.show', $a->getKey()));

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->endOfYear()->toDateString())];
        $catalog->register(new ReportDefinition('activities.trip-cost', 'Trip cost', 'Activities', 'activities', fn (array $f, ?User $u = null): array => app(ActivityReports::class)->tripCost(...[...$range($f), $u])));
        $catalog->register(new ReportDefinition('activities.register', 'Activity register', 'Activities', 'activities', fn (array $f, ?User $u = null): array => app(ActivityReports::class)->register(...[...$range($f), $u])));
    }
}
