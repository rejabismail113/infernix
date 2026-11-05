<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\Permission;
use App\Kernel\Identity\Services\DelegationService;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/** @see SRS UAT-01, G-02, M12-02, NFR-02, §2 */
it('UAT-01: STF cannot open another user\'s record via a changed URL, but can open their own', function (): void {
    $staff = userWithRoles('STF');
    $other = userWithRoles('STF');

    actingAsSatisfied($staff)->get(route('admin.users.show', $other))->assertForbidden();
    actingAsSatisfied($staff)->get(route('admin.users.show', $staff))->assertOk();
    // No leakage through the list either: only self is returned.
    $page = actingAsSatisfied($staff)->get(route('admin.users.index'))->assertOk();
    $ids = collect($page->viewData('page')['props']['users'])->pluck('id')->all();
    expect($ids)->toBe([$staff->id]);
})->group('UAT-01', 'G-02', 'M12-02', 'NFR-02');

it('UAT-01: AUD can read and export but every write is denied', function (): void {
    $aud = userWithRoles('AUD');
    $staff = userWithRoles('STF');

    actingAsSatisfied($aud)->get(route('admin.audit-log.index'))->assertOk();
    actingAsSatisfied($aud)->get(route('admin.audit-log.export'))->assertOk();
    actingAsSatisfied($aud)->get(route('admin.users.show', $staff))->assertOk();

    actingAsSatisfied($aud)->post(route('admin.users.invite'), ['email' => 'x@example.test', 'role' => 'STF'])->assertForbidden();
    actingAsSatisfied($aud)->post(route('admin.users.deactivate', $staff), ['reason' => 'x'])->assertForbidden();
    actingAsSatisfied($aud)->put(route('admin.permissions.update'), ['role_id' => 1, 'resource' => 'users', 'action' => 'view', 'record_scope' => 'all', 'reason' => 'x'])->assertForbidden();
    actingAsSatisfied($aud)->post(route('admin.fiscal-years.store'), ['year' => 2031])->assertForbidden();

    // Even if someone seeds a write permission for AUD, the read-only guard still denies it.
    $p = app(PermissionService::class);
    Permission::withoutEvents(fn () => Permission::query()->create([
        'role_id' => $aud->roles->first()->id, 'resource' => 'users', 'action' => 'delete', 'record_scope' => 'all', 'policy_version' => 9, 'effective_from' => now(),
    ]));
    $p->forget();
    expect($p->can($aud->fresh(['roles']), PermissionAction::Delete, 'users'))->toBeFalse();
})->group('UAT-01', 'G-02', 'M12-02', '§2');

it('denies by default: an ability nobody defined is 403 for every role, even OWN', function (): void {
    Route::middleware(['web', 'app', 'can:do-something-unregistered'])->get('/_test/unregistered', fn () => 'nope');
    foreach (['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP'] as $role) {
        actingAsSatisfied(userWithRoles($role))->get('/_test/unregistered')->assertForbidden();
    }
    expect(Gate::forUser(ownerUser())->allows('totally-unknown-ability'))->toBeFalse();
})->group('G-02', 'M12-02', 'NFR-02');

it('denies a resource/action pair absent from the matrix', function (): void {
    $p = app(PermissionService::class);
    $owner = ownerUser();
    expect($p->can($owner, PermissionAction::View, 'users'))->toBeTrue()
        ->and($p->can($owner, PermissionAction::View, 'resource_that_does_not_exist'))->toBeFalse()
        ->and($p->can($owner, 'not-an-action', 'users'))->toBeFalse();
})->group('G-02', 'M12-02');

it('hides sidebar entries the user lacks permission for, while the route itself still returns 403', function (): void {
    $staff = userWithRoles('STF');
    $page = actingAsSatisfied($staff)->get('/')->assertOk();
    $labels = collect($page->viewData('page')['props']['nav'])->pluck('label')->all();
    expect($labels)->not->toContain('Audit log')->not->toContain('Permissions');
    actingAsSatisfied($staff)->get(route('admin.audit-log.index'))->assertForbidden();
    actingAsSatisfied($staff)->get(route('admin.permissions.index'))->assertForbidden();
})->group('G-02', 'NFR-02');

it('expires AUD access outside the time window (and the session is ended)', function (): void {
    $aud = userWithRoles('AUD');
    $aud->forceFill(['access_from' => now()->subDays(10), 'access_until' => now()->subDay()])->save();

    expect(app(PermissionService::class)->can($aud->fresh(['roles']), PermissionAction::View, 'audit_log'))->toBeFalse();
    actingAsSatisfied($aud->fresh())->get(route('admin.audit-log.index'))->assertRedirect(route('login'));
    $this->assertGuest();

    // Login itself is refused outside the window.
    $this->from('/login')->post('/login', ['email' => $aud->email, 'password' => 'password'])->assertSessionHasErrors('email');

    // Inside the window everything works.
    $aud->forceFill(['access_from' => now()->subDay(), 'access_until' => now()->addDay()])->save();
    actingAsSatisfied($aud->fresh())->get(route('admin.audit-log.index'))->assertOk();
})->group('§2', 'M12-01', 'NFR-02');

it('treats the widest scope across multiple roles as effective', function (): void {
    $user = userWithRoles('STF', 'HR');
    $other = userWithRoles('STF');
    expect(app(PermissionService::class)->can($user, PermissionAction::View, $other))->toBeTrue();
    expect(app(PermissionService::class)->can(userWithRoles('STF'), PermissionAction::View, $other))->toBeFalse();
})->group('G-02', 'M12-02');

it('requires recent re-authentication for sensitive mutations', function (): void {
    $owner = ownerUser();
    $staff = userWithRoles('STF');
    // Owner with MFA satisfied but NO recent re-authentication in the session.
    actingAsSatisfied($owner);
    $this->flushSession();
    $this->actingAs($owner)->withSession([EnsureMfa::SESSION_KEY => now()->toIso8601String()])
        ->post(route('admin.users.deactivate', $staff), ['reason' => 'x'])
        ->assertRedirectContains('/reauth');
    expect($staff->fresh()->isActive())->toBeTrue();

    $this->actingAs($owner)->withSession([EnsureMfa::SESSION_KEY => now()->toIso8601String()])
        ->post(route('reauth.store'), ['password' => config('infernix.seed_owner.password')])
        ->assertRedirect()->assertSessionHas(RequireRecentAuth::SESSION_KEY);
})->group('§2', 'M12-04', 'M13-03');

it('APP delegate may approve only within entity type, amount ceiling and date window', function (): void {
    $delegator = userWithRoles('FIN');
    $delegate = userWithRoles('APP');
    $svc = app(DelegationService::class);
    $myr = fn (string $a) => Money::myr($a);

    $d = $svc->create([
        'delegator_id' => $delegator->id, 'delegate_id' => $delegate->id, 'entity_type' => 'claim', 'max_amount' => $myr('1000'),
        'valid_from' => now()->subDay(), 'valid_to' => now()->addDays(7), 'reason' => 'Annual leave cover',
    ], $delegator);

    expect($svc->canApprove($delegate, 'claim', $myr('999.99')))->toBeTrue()
        ->and($svc->canApprove($delegate, 'claim', $myr('1000.00')))->toBeTrue()
        ->and($svc->canApprove($delegate, 'claim', $myr('1000.01')))->toBeFalse()
        ->and($svc->canApprove($delegate, 'payment', $myr('10')))->toBeFalse()
        ->and($svc->canApprove($delegate, 'claim', $myr('10'), now()->addDays(8)))->toBeFalse()
        ->and($svc->canApprove(userWithRoles('APP'), 'claim', $myr('10')))->toBeFalse();

    // Revision creates a new version; the old one is superseded and never edited in place.
    $v2 = $svc->revise($d, ['max_amount' => $myr('5000'), 'reason' => 'Ceiling raised for quarter-end'], $delegator);
    expect($v2->version)->toBe(2)->and($v2->supersedes_id)->toBe($d->id)
        ->and($svc->canApprove($delegate, 'claim', $myr('4000')))->toBeTrue();
    // Savepoint so the surrounding test transaction survives the expected trigger error.
    expect(fn () => DB::transaction(fn () => DB::table('approval_delegations')->where('id', $d->id)->update(['max_amount' => '9.0000'])))
        ->toThrow(QueryException::class, 'never edited in place');

    $svc->revoke($v2, 'No longer needed', $delegator);
    expect($svc->canApprove($delegate->fresh(), 'claim', $myr('10')))->toBeFalse();
    expect(AuditEvent::query()->where('action', 'permission_change')->where('entity_type', ApprovalDelegation::class)->count())->toBeGreaterThanOrEqual(2);
})->group('M12-03', '§2.1');
