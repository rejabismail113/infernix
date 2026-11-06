<?php

declare(strict_types=1);

namespace App\Modules\Csr;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Csr\Models\CsrProgramme;
use App\Modules\Csr\Policies\CsrProgrammePolicy;
use App\Modules\Csr\Reports\CsrReports;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M08 — CSR.
 *
 * @see SRS §4 M08
 * @see docs/ARCHITECTURE.md
 */
final class CsrServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M08';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(CsrProgramme::class, CsrProgrammePolicy::class);

        // Programme files are evidence photos: reachable only with csr_evidence:view (M08-04), never by inheritance alone.
        $this->app->make(DocumentLinkRegistry::class)->register(new LinkableEntityType('csr_programme', CsrProgramme::class, 'CSR programme', ['photo', 'report', 'evidence', 'attachment', 'other'],
            accessibleIds: fn (User $u): array|string => app(PermissionService::class)->can($u, PermissionAction::View, 'csr_evidence')
                && app(PermissionService::class)->scopeFor($u, 'csr_programmes', PermissionAction::View) === RecordScope::All ? '*' : [],
            labelFor: fn (CsrProgramme $p): string => "{$p->programme_no} {$p->title}"));
        $this->app->make(SearchRegistry::class)->register('csr_programme', CsrProgramme::class, 'CSR programme', fn (Model $p): string => route('csr.programmes.show', $p->getKey()));

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->endOfYear()->toDateString())];
        $catalog->register(new ReportDefinition('csr.spend', 'CSR spend (cash vs in-kind)', 'CSR', 'csr_programmes', fn (array $f): array => app(CsrReports::class)->spend(...$range($f))));
        $catalog->register(new ReportDefinition('csr.beneficiaries', 'CSR activities & beneficiaries', 'CSR', 'csr_programmes', fn (array $f): array => app(CsrReports::class)->beneficiaries(...$range($f))));
    }
}
