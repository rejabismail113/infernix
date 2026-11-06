<?php

declare(strict_types=1);

namespace App\Modules\Reporting;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Modules\Reporting\Models\Export;
use App\Modules\Reporting\Policies\ExportPolicy;
use App\Modules\Reporting\Reports\ProjectContributionReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M11 — Reporting: dashboards (metric registry) and the Export Centre. Read-only consumer of the report
 * functions other modules expose (docs/ARCHITECTURE.md §3).
 *
 * @see SRS §4 M11
 * @see docs/ARCHITECTURE.md
 */
final class ReportingServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M11';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Export::class, ExportPolicy::class);
        $this->app->make(ReportCatalog::class)->register(new ReportDefinition('projects.contribution', 'Revenue & contribution by project', 'Projects', 'projects',
            fn (array $f, ?User $u = null): array => app(ProjectContributionReport::class)->run(Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->toDateString()), $u)));
        $this->app->make(DocumentLinkRegistry::class)->register(new LinkableEntityType('export', Export::class, 'Export', ['export_file'],
            ownedIds: fn ($user): array => Export::query()->where('user_id', $user->id)->pluck('id')->all(),
            labelFor: fn (Export $e): string => $e->report_key.' '.$e->created_at->toDateString()));
    }
}
