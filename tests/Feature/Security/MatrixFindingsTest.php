<?php

declare(strict_types=1);

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Credentials\Services\AccessService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\HumanResources\Models\StaffBankDetail;
use Illuminate\Support\Facades\Gate;

/*
 * Regressions for defects the route × role matrix (P6-6) found. See docs/DECISIONS.md A-92.
 */
it('IDOR: a staff member can edit only their own bank details; HR / owner can edit anyone\'s', function (): void {
    $victimUser = userWithRoles('STF');
    $victim = makeStaff($victimUser);
    $attacker = userWithRoles('STF');
    makeStaff($attacker);
    $payload = ['bank_name' => 'Maybank', 'account_no' => '1122334455', 'account_name' => 'Attacker'];

    actingAsSatisfied($attacker)->put(route('hr.staff.bank', $victim->id), $payload)->assertForbidden();
    expect(StaffBankDetail::query()->where('staff_profile_id', $victim->id)->exists())->toBeFalse()
        ->and(Gate::forUser($attacker)->allows('edit-staff-bank'))->toBeFalse();

    actingAsSatisfied($victimUser)->put(route('hr.staff.bank', $victim->id), $payload + ['account_name' => 'Victim'])->assertRedirect()->assertSessionHas('success');
    actingAsSatisfied(userWithRoles('HR'))->put(route('hr.staff.bank', $victim->id), $payload + ['account_name' => 'Victim (HR)'])->assertRedirect()->assertSessionHas('success');
})->group('NFR-02', 'UAT-10', 'M04-02');

it('company-wide gates need an `all` grant — an own-scoped grant never satisfies them', function (): void {
    $perm = app(PermissionService::class);
    $stf = userWithRoles('STF');
    expect($perm->can($stf, PermissionAction::Edit, 'staff_bank'))->toBeTrue()        // may open their own form
        ->and($perm->canOnAll($stf, PermissionAction::Edit, 'staff_bank'))->toBeFalse()
        ->and($perm->canOnAll(userWithRoles('HR'), PermissionAction::Edit, 'staff_bank'))->toBeTrue()
        ->and($perm->canOnAll(ownerUser(), PermissionAction::Reveal, 'staff_bank'))->toBeTrue();
    foreach (['reveal-staff-identifier', 'reveal-staff-bank', 'edit-staff-bank', 'set-tax-treatment', 'view-finance-reports', 'export-finance-reports', 'view-access-reports', 'export-access-reports'] as $gate) {
        expect(Gate::forUser($stf)->allows($gate))->toBeFalse("STF must not pass {$gate}");
    }
})->group('NFR-02', 'M12-02');

it('access can be granted to a credential that has no vault adapter (vault_sync not_applicable)', function (): void {
    $owner = ownerUser();
    $cred = app(CredentialService::class)->save(null, ['system_name' => 'Router admin', 'category' => 'cloud', 'environment' => 'production', 'owner_id' => $owner->id,
        'vault_provider' => 'none', 'sensitivity' => 'high'], $owner);
    $e = app(AccessService::class)->grant($cred, userWithRoles('FIN'), $owner);
    expect($e->vault_sync)->toBe('not_applicable');
})->group('M13-03');
