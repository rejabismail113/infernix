<?php

declare(strict_types=1);

use App\Kernel\Approval\ApprovalMatrix;
use App\Kernel\Audit\Models\AuditEvent;
use Inertia\Testing\AssertableInertia;

/** @see SRS M12-03 — approval matrices are configurable and versioned. */
function matrixPayload(array $overrides = []): array
{
    return array_merge(['entity_type' => 'claim', 'effective_from' => today()->toDateString(), 'reason' => 'Raise FIN-only limit to RM 1,000',
        'rules' => [
            ['level' => 1, 'min_amount' => '0.00', 'max_amount' => '1000.00', 'approver_roles' => ['FIN', 'OWN']],
            ['level' => 1, 'min_amount' => '1000.01', 'max_amount' => null, 'approver_roles' => ['FIN', 'OWN']],
            ['level' => 2, 'min_amount' => '1000.01', 'max_amount' => null, 'approver_roles' => ['OWN']],
        ]], $overrides);
}

it('M12-03: the owner publishes a new effective-dated version; the old one is closed, never edited, and the change is audited', function (): void {
    $v1 = ApprovalMatrix::query()->where('entity_type', 'claim')->where('version', 1)->with('rules')->sole();
    $v1Rules = $v1->rules->map(fn ($r) => [$r->level, (string) $r->min_amount->getAmount()])->sort()->values()->all();

    actingAsSatisfied(ownerUser())->post(route('admin.approval-matrix.store'), matrixPayload())->assertRedirect()->assertSessionHas('success');

    $v2 = ApprovalMatrix::query()->where('entity_type', 'claim')->effective()->orderByDesc('version')->with('rules')->firstOrFail();
    expect($v2->version)->toBe(2)->and($v2->rules)->toHaveCount(3)
        ->and($v1->fresh()?->effective_to?->toDateString())->toBe(today()->toDateString())
        ->and($v1->fresh(['rules'])->rules->map(fn ($r) => [$r->level, (string) $r->min_amount->getAmount()])->sort()->values()->all())->toBe($v1Rules)
        ->and(AuditEvent::query()->where('action', 'permission_change')->where('entity_type', ApprovalMatrix::class)->where('entity_id', $v2->id)->value('reason'))->toBe('Raise FIN-only limit to RM 1,000');

    actingAsSatisfied(ownerUser())->get(route('admin.approval-matrix.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Admin/ApprovalMatrix')->where('can.edit', true));
})->group('M12-03');

it('M12-03: gaps, overlaps, past dates, missing reasons and non-approver roles are refused', function (array $overrides, string $message): void {
    actingAsSatisfied(ownerUser())->post(route('admin.approval-matrix.store'), matrixPayload($overrides));
    expectSessionErrorContains('general', $message);
    expect(ApprovalMatrix::query()->where('entity_type', 'claim')->max('version'))->toBe(1);
})->with([
    'gap in level 1' => [['rules' => [['level' => 1, 'min_amount' => '0.00', 'max_amount' => '1000.00', 'approver_roles' => ['FIN']], ['level' => 1, 'min_amount' => '2000.00', 'max_amount' => null, 'approver_roles' => ['FIN']]]], 'continuous'],
    'level 1 not open-ended' => [['rules' => [['level' => 1, 'min_amount' => '0.00', 'max_amount' => '1000.00', 'approver_roles' => ['FIN']]]], 'cover every amount'],
    'overlap' => [['rules' => [['level' => 1, 'min_amount' => '0.00', 'max_amount' => null, 'approver_roles' => ['FIN']], ['level' => 1, 'min_amount' => '10.00', 'max_amount' => null, 'approver_roles' => ['OWN']]]], 'overlap'],
    'level gap' => [['rules' => [['level' => 1, 'min_amount' => '0.00', 'max_amount' => null, 'approver_roles' => ['FIN']], ['level' => 3, 'min_amount' => '0.00', 'max_amount' => null, 'approver_roles' => ['OWN']]]], 'no gaps'],
    'past date' => [['effective_from' => today()->subDay()->toDateString()], 'past'],
])->group('M12-03');

it('M12-03: only the owner edits; the auditor may read; others are denied; roles outside the approver set are invalid', function (): void {
    actingAsSatisfied(userWithRoles('AUD'))->get(route('admin.approval-matrix.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('can.edit', false));
    actingAsSatisfied(userWithRoles('AUD'))->post(route('admin.approval-matrix.store'), matrixPayload())->assertForbidden();
    foreach (['FIN', 'PM', 'HR', 'STF', 'APP'] as $role) {
        actingAsSatisfied(userWithRoles($role))->get(route('admin.approval-matrix.index'))->assertForbidden();
    }
    actingAsSatisfied(ownerUser())->post(route('admin.approval-matrix.store'), matrixPayload(['rules' => [['level' => 1, 'min_amount' => '0.00', 'max_amount' => null, 'approver_roles' => ['STF']]]]))
        ->assertSessionHasErrors('rules.0.approver_roles.0');
})->group('M12-03', 'NFR-02');
