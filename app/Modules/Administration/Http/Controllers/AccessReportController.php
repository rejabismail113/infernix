<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\CsvExport;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * User access report (who holds which role/permission, MFA, last login, AUD window) and policy history
 * (every permission version + delegation version + permission_change audit events).
 *
 * @see SRS M12-04 outputs: "User access report; Policy history"
 */
final class AccessReportController
{
    use AuthorizesRequests;

    public function userAccess(Request $request): Response
    {
        Gate::authorize('view-access-reports');

        return Inertia::render('Admin/Reports/UserAccess', [
            'rows' => $this->userAccessRows(),
            'can' => ['export' => Gate::allows('export-access-reports')],
        ]);
    }

    public function userAccessExport(CsvExport $csv): StreamedResponse
    {
        Gate::authorize('export-access-reports');

        $rows = [];
        foreach ($this->userAccessRows() as $r) {
            foreach ($r['permissions'] as $p) {
                $rows[] = [$r['name'], $r['email'], implode('|', $r['roles']), $r['active'] ? 'yes' : 'no', $r['mfa_enabled'] ? 'yes' : 'no', $r['last_login_at'], $r['access_from'], $r['access_until'], $p['resource'], $p['action'], $p['record_scope']];
            }
        }

        return $csv->stream('user_access_report', ['user', 'email', 'roles', 'active', 'mfa_enabled', 'last_login_at', 'access_from', 'access_until', 'resource', 'action', 'record_scope'], $rows);
    }

    public function policyHistory(): Response
    {
        Gate::authorize('view-access-reports');

        return Inertia::render('Admin/Reports/PolicyHistory', ['rows' => $this->policyHistoryRows(), 'can' => ['export' => Gate::allows('export-access-reports')]]);
    }

    public function policyHistoryExport(CsvExport $csv): StreamedResponse
    {
        Gate::authorize('export-access-reports');

        return $csv->stream('policy_history', ['kind', 'id', 'subject', 'detail', 'version', 'effective_from', 'effective_to', 'changed_by', 'reason'],
            array_map(fn (array $r): array => array_values($r), $this->policyHistoryRows()));
    }

    /** @return list<array<string, mixed>> */
    private function userAccessRows(): array
    {
        $perms = Permission::query()->effective()->get()->groupBy('role_id');

        return User::query()->with('roles')->orderBy('name')->get()->map(fn (User $u): array => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'roles' => $u->roles->pluck('code')->all(), 'active' => $u->isActive(),
            'mfa_enabled' => $u->mfaEnabled(), 'last_login_at' => $u->last_login_at?->toIso8601String(), 'access_from' => $u->access_from?->toIso8601String(), 'access_until' => $u->access_until?->toIso8601String(),
            'permissions' => $u->roles->flatMap(fn ($r) => $perms->get($r->id, collect()))->map(fn (Permission $p) => ['resource' => $p->resource, 'action' => $p->action->value, 'record_scope' => $p->record_scope->value])->unique(fn ($p) => $p['resource'].$p['action'])->values()->all(),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function policyHistoryRows(): array
    {
        $permissions = Permission::query()->with('role')->orderBy('resource')->orderBy('action')->orderBy('policy_version')->get()->map(fn (Permission $p): array => [
            'kind' => 'permission', 'id' => $p->id, 'subject' => $p->role->code, 'detail' => "{$p->resource}.{$p->action->value} = {$p->record_scope->value}", 'version' => $p->policy_version,
            'effective_from' => $p->effective_from->toIso8601String(), 'effective_to' => $p->effective_to?->toIso8601String(), 'changed_by' => $p->changed_by, 'reason' => null,
        ]);
        $delegations = ApprovalDelegation::query()->with(['delegator:id,name', 'delegate:id,name'])->orderBy('id')->get()->map(fn (ApprovalDelegation $d): array => [
            'kind' => 'delegation', 'id' => $d->id, 'subject' => $d->delegator->name.' → '.$d->delegate->name, 'detail' => $d->entity_type.' ≤ '.($d->max_amount?->getAmount()->__toString() ?? 'unlimited'), 'version' => $d->version,
            'effective_from' => $d->valid_from->toIso8601String(), 'effective_to' => $d->revoked_at?->toIso8601String() ?? $d->valid_to->toIso8601String(), 'changed_by' => $d->created_by, 'reason' => $d->reason,
        ]);
        $changes = AuditEvent::query()->where('action', 'permission_change')->orderBy('id')->get()->map(fn (AuditEvent $e): array => [
            'kind' => 'audit', 'id' => $e->id, 'subject' => class_basename($e->entity_type).'#'.$e->entity_id, 'detail' => json_encode($e->after), 'version' => null,
            'effective_from' => $e->occurred_at->toIso8601String(), 'effective_to' => null, 'changed_by' => $e->actor_id, 'reason' => $e->reason,
        ]);

        return $permissions->concat($delegations)->concat($changes)->values()->all();
    }
}
