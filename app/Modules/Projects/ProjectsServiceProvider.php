<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Kernel\Search\SearchIndexer;
use App\Kernel\Search\SearchRegistry;
use App\Modules\Commercial\Events\ProjectBillingChanged;
use App\Modules\Projects\Listeners\UpdateProjectPaymentStatus;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ClientPolicy;
use App\Modules\Projects\Policies\LeadPolicy;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Reports\ProjectsReports;
use App\Modules\Projects\Services\ClientsDirectory;
use App\Modules\Projects\Services\ProjectsDirectory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M02 — Projects & Training (Prompt 3: client master + project stub; Prompt 4 completes it).
 *
 * @see SRS §4 M02
 * @see docs/ARCHITECTURE.md
 */
final class ProjectsServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M02';

    public function register(): void
    {
        $this->app->bind(ClientDirectory::class, ClientsDirectory::class);
        $this->app->bind(ProjectDirectory::class, ProjectsDirectory::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Lead::class, LeadPolicy::class);
        // Global search (G-11): every hit is re-checked against the record policy by SearchService.
        $search = $this->app->make(SearchRegistry::class);
        $search->register('project', Project::class, 'Project', fn (Model $p): string => route('projects.projects.show', $p->getKey()));
        $search->register('client', Client::class, 'Client', fn (Model $c): string => route('projects.clients.index', ['q' => $c->getAttribute('name')]));
        Project::saved(fn (Project $p) => app(SearchIndexer::class)->index('project', $p->id, trim($p->code.' '.$p->name), $p->description, 'Projects'));
        Client::saved(fn (Client $c) => app(SearchIndexer::class)->index('client', $c->id, $c->name, trim(($c->registration_no ?? '').' '.($c->contact_person ?? '')), 'Projects'));
        Event::listen(ProjectBillingChanged::class, UpdateProjectPaymentStatus::class);

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->toDateString())];
        $catalog->register(new ReportDefinition('projects.pipeline', 'Project pipeline', 'Projects', 'leads', fn (array $f, ?User $u = null): array => app(ProjectsReports::class)->pipeline($u), []));
        $catalog->register(new ReportDefinition('projects.overdue-milestones', 'Overdue milestones', 'Projects', 'projects', fn (array $f, ?User $u = null): array => app(ProjectsReports::class)->overdueMilestones($u, Carbon::parse($f['as_of'] ?? today()->toDateString())), ['as_of']));
        $catalog->register(new ReportDefinition('projects.training-register', 'Training register', 'Projects', 'projects', fn (array $f, ?User $u = null): array => app(ProjectsReports::class)->trainingRegister($u, ...$range($f))));

        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('client', Client::class, 'Client', ['client_po', 'contract', 'evidence', 'attachment'], labelFor: fn (Client $c): string => $c->name));
        // Project documents follow the project record scope: a PM sees only assigned projects' files (M02-07).
        $registry->register(new LinkableEntityType('project', Project::class, 'Project', ['contract', 'client_po', 'agreement', 'report', 'evidence', 'attachment', 'other'],
            accessibleIds: fn (User $u): array|string => app(ProjectDirectory::class)->visibleProjectIds($u),
            ownedIds: fn (User $u): array => Project::query()->where('created_by', $u->id)->pluck('id')->all(),
            labelFor: fn (Project $p): string => "{$p->code} {$p->name}"));
        $registry->register(new LinkableEntityType('lead', Lead::class, 'Lead', ['evidence', 'attachment'], labelFor: fn (Lead $l): string => $l->title));
    }
}
