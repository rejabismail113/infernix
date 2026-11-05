<?php

declare(strict_types=1);

namespace App\Modules\Projects;

use App\Kernel\Crm\Contracts\ClientDirectory;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Modules\Commercial\Events\ProjectBillingChanged;
use App\Modules\Projects\Listeners\UpdateProjectPaymentStatus;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ClientPolicy;
use App\Modules\Projects\Policies\ProjectPolicy;
use App\Modules\Projects\Services\ClientsDirectory;
use App\Modules\Projects\Services\ProjectsDirectory;
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
        Event::listen(ProjectBillingChanged::class, UpdateProjectPaymentStatus::class);

        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('client', Client::class, 'Client', ['client_po', 'contract', 'evidence', 'attachment'], labelFor: fn (Client $c): string => $c->name));
        $registry->register(new LinkableEntityType('project', Project::class, 'Project', ['contract', 'client_po', 'evidence', 'attachment'], labelFor: fn (Project $p): string => "{$p->code} {$p->name}"));
    }
}
