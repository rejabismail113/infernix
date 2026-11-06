<?php

declare(strict_types=1);

namespace App\Modules\Certifications;

use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Models\User;
use App\Kernel\People\Contracts\StaffDirectory;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Certifications\Models\Certification;
use App\Modules\Certifications\Models\Membership;
use App\Modules\Certifications\Policies\CertificationPolicy;
use App\Modules\Certifications\Policies\MembershipPolicy;
use App\Modules\Certifications\Reports\CertificationReports;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M09 — Certification & Membership.
 *
 * @see SRS §4 M09
 * @see docs/ARCHITECTURE.md
 */
final class CertificationsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M09';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Certification::class, CertificationPolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);

        $own = fn (string $model) => fn (User $u): array => $model::query()->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('staff_profile_id', app(StaffDirectory::class)->idForUser($u) ?? 0))->pluck('id')->all();
        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('certification', Certification::class, 'Certification', ['certificate', 'evidence', 'receipt', 'attachment', 'other'],
            ownedIds: $own(Certification::class), labelFor: fn (Certification $c): string => "{$c->name} ({$c->issuer})"));
        $registry->register(new LinkableEntityType('membership', Membership::class, 'Membership', ['certificate', 'evidence', 'receipt', 'attachment', 'other'],
            ownedIds: $own(Membership::class), labelFor: fn (Membership $m): string => "{$m->organisation} membership"));
        $search = $this->app->make(SearchRegistry::class);
        $search->register('certification', Certification::class, 'Certification', fn (Model $c): string => route('certifications.certifications.index'));
        $search->register('membership', Membership::class, 'Membership', fn (Model $m): string => route('certifications.certifications.index', ['tab' => 'memberships']));

        $catalog = $this->app->make(ReportCatalog::class);
        $catalog->register(new ReportDefinition('certifications.expiring', 'Expiring certificates & memberships', 'Certifications', 'certifications',
            fn (array $f): array => app(CertificationReports::class)->expiring(Carbon::parse($f['from'] ?? today()->toDateString()), Carbon::parse($f['to'] ?? today()->addDays(90)->toDateString()))));
        $catalog->register(new ReportDefinition('certifications.expenditure', 'Renewal expenditure', 'Certifications', 'certifications',
            fn (array $f): array => app(CertificationReports::class)->expenditure(Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->endOfYear()->toDateString()))));
    }
}
