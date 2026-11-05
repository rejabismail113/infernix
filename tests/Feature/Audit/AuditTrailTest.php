<?php

declare(strict_types=1);

use App\Kernel\Audit\Audit;
use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Administration\Models\FiscalYear;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @see SRS G-06, M12-04, NFR-03 */
it('is append-only: UPDATE and DELETE on audit_events raise at the database level', function (): void {
    $event = Audit::log('test', 'Thing', 1, null, ['a' => 1]);

    // Each attempt runs in a savepoint: Postgres aborts the surrounding transaction after an error.
    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->update(['action' => 'tampered'])))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::table('audit_events')->where('id', $event->id)->delete()))
        ->toThrow(QueryException::class, 'append-only');
    expect(fn () => DB::transaction(fn () => DB::statement('DELETE FROM audit_events')))->toThrow(QueryException::class, 'append-only');
    expect(AuditEvent::query()->find($event->id)?->action)->toBe('test');
})->group('G-06', 'M12-04', 'NFR-03');

it('redacts PII and secret fields in before/after JSON and records what was redacted', function (): void {
    $event = Audit::log('update', 'Staff', 7,
        ['name' => 'Ali', 'id_number' => '900101-14-5555', 'bank' => ['account_no' => '1234567890']],
        ['name' => 'Ali B', 'password' => 'hunter22', 'api_key' => 'sk-live-abc', 'clinical_note' => 'flu'],
    );
    $json = json_encode($event->fresh());

    expect($event->before['id_number'])->toBe('[REDACTED]')
        ->and($event->before['bank']['account_no'])->toBe('[REDACTED]')
        ->and($event->after['password'])->toBe('[REDACTED]')
        ->and($event->after['clinical_note'])->toBe('[REDACTED]')
        ->and($event->after['name'])->toBe('Ali B')
        ->and($event->redactions)->toContain('id_number', 'bank.account_no', 'password', 'api_key', 'clinical_note')
        ->and($json)->not->toContain('900101')->not->toContain('hunter22')->not->toContain('sk-live')->not->toContain('1234567890');
})->group('NFR-03', 'NFR-07', 'M13-05');

it('scrubs the dev secret sentinel from any audit payload or reason', function (): void {
    $sentinel = config('infernix.secret_sentinel');
    $event = Audit::log('note', 'Thing', 1, null, ['memo' => "token is {$sentinel}"], "reason {$sentinel}");
    expect(json_encode($event->fresh()))->not->toContain($sentinel);
})->group('UAT-08', 'M13-05');

it('records create/update/delete of auditable models with actor, IP and request id', function (): void {
    $owner = ownerUser();
    actingAsSatisfied($owner)->post(route('admin.fiscal-years.store'), ['year' => 2030])->assertSessionHasNoErrors();

    $e = AuditEvent::query()->where('action', 'create')->where('entity_type', FiscalYear::class)->latest('id')->first();
    expect($e)->not->toBeNull()
        ->and($e->actor_id)->toBe($owner->id)
        ->and($e->actor_role)->toBe('OWN')
        ->and($e->after['year'])->toBe(2030)
        ->and($e->ip)->not->toBeNull()
        ->and($e->request_id)->not->toBeNull();
})->group('G-06', 'M12-04');

it('audits period lock/unlock with reason, and only OWN/FIN may do it', function (): void {
    $owner = ownerUser();
    $period = FiscalPeriod::query()->orderBy('id')->firstOrFail();

    actingAsSatisfied(userWithRoles('HR'))->post(route('admin.fiscal-periods.lock', $period), ['reason' => 'x'])->assertForbidden();
    actingAsSatisfied($owner)->post(route('admin.fiscal-periods.lock', $period), ['reason' => 'Month closed'])->assertSessionHasNoErrors();
    expect($period->fresh()->isLocked())->toBeTrue();
    expect(AuditEvent::query()->where('action', 'period_lock')->where('entity_id', $period->id)->where('reason', 'Month closed')->exists())->toBeTrue();

    actingAsSatisfied($owner)->post(route('admin.fiscal-periods.unlock', $period), ['reason' => 'Adjustment approved'])->assertSessionHasNoErrors();
    expect($period->fresh()->isLocked())->toBeFalse();
    expect(AuditEvent::query()->where('action', 'period_unlock')->exists())->toBeTrue();
})->group('M01-08', 'M12-05', 'G-06');

it('audits permission changes as a new policy version and keeps the history', function (): void {
    $owner = ownerUser();
    $role = Role::byCode('STF');
    actingAsSatisfied($owner)->put(route('admin.permissions.update'), ['role_id' => $role->id, 'resource' => 'users', 'action' => 'view', 'record_scope' => 'all', 'reason' => 'Directory visible to all'])
        ->assertSessionHasNoErrors();
    $versions = Permission::query()->where('role_id', $role->id)->where('resource', 'users')->where('action', 'view')->orderBy('policy_version')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions[0]->effective_to)->not->toBeNull()
        ->and($versions[1]->record_scope->value)->toBe('all');
    expect(AuditEvent::query()->where('action', 'permission_change')->where('reason', 'Directory visible to all')->exists())->toBeTrue();
    // Effective immediately for the role.
    expect(app(PermissionService::class)->can(userWithRoles('STF'), 'view', userWithRoles('STF')))->toBeTrue();
    actingAsSatisfied($owner)->get(route('admin.reports.policy-history'))->assertOk();
})->group('M12-02', 'M12-04', 'G-06');

it('audits every CSV export and no export ever contains the secret sentinel', function (): void {
    $owner = ownerUser();
    $sentinel = config('infernix.secret_sentinel');
    Audit::log('note', 'Thing', 1, null, ['x' => 'y'], "contains {$sentinel} secret");
    User::factory()->create(['name' => "Mallory {$sentinel}"]);

    foreach (['admin.audit-log.export', 'admin.reports.user-access.export', 'admin.reports.policy-history.export'] as $route) {
        $res = actingAsSatisfied($owner)->get(route($route))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        expect($res->streamedContent())->not->toContain($sentinel);
    }
    expect(AuditEvent::query()->where('action', 'export')->pluck('entity_type')->all())
        ->toContain('audit_log', 'user_access_report', 'policy_history');
})->group('G-06', 'M12-04', 'G-08', 'UAT-08', 'M13-05');
