<?php

declare(strict_types=1);

namespace App\Kernel;

use App\Kernel\Audit\AuditEventPolicy;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Models\UserInvitation;
use App\Kernel\Identity\Policies\ApprovalDelegationPolicy;
use App\Kernel\Identity\Policies\PermissionPolicy;
use App\Kernel\Identity\Policies\UserInvitationPolicy;
use App\Kernel\Identity\Policies\UserPolicy;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Numbering\Models\NumberingSequence;
use App\Kernel\Numbering\NumberingSequencePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Shared kernel: Identity, Audit, Documents (contracts), Numbering, Money, Approval, Notifications.
 * Business modules (App\Modules\*) depend on the kernel; the kernel never depends on a module.
 *
 * @see docs/ARCHITECTURE.md
 */
final class KernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/Identity/Routes/web.php');

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(UserInvitation::class, UserInvitationPolicy::class);
        Gate::policy(ApprovalDelegation::class, ApprovalDelegationPolicy::class);
        Gate::policy(Permission::class, PermissionPolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(NumberingSequence::class, NumberingSequencePolicy::class);

        // Named abilities for report pages (resource "reports_access" in the matrix).
        Gate::define('view-access-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::View, 'reports_access'));
        Gate::define('export-access-reports', fn (User $u): bool => app(PermissionService::class)->can($u, PermissionAction::Export, 'reports_access'));
    }
}
