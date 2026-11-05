<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Enums\RoleCode;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\User;
use Brick\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Approval chain resolution (matrix → levels → roles), delegation and maker-checker.
 *
 * Rules: the approver must hold one of the level's roles directly, or an active delegation for the
 * entity type and amount from someone who holds it. The preparer can never approve their own item —
 * except an Owner who records a reason (owner override, audited).
 *
 * @see SRS M12-03, §2.1, M01-03, UAT-11
 */
final class ApprovalService
{
    /** @return list<ApprovalMatrixRule> rules (one per level) that apply to this amount */
    public function levels(string $entityType, Money $amount): array
    {
        /** @var ApprovalMatrix|null $matrix */
        $matrix = ApprovalMatrix::query()->where('entity_type', $entityType)->effective()->orderByDesc('version')->with('rules')->first();
        if ($matrix === null) {
            return [];
        }
        $byLevel = [];
        foreach ($matrix->rules as $rule) {
            if ($rule->covers($amount)) {
                $byLevel[$rule->level] ??= $rule;
            }
        }
        ksort($byLevel);

        return array_values($byLevel);
    }

    /** @return list<string> role codes that may approve $level of an item of this type and amount */
    public function rolesFor(string $entityType, Money $amount, int $level): array
    {
        foreach ($this->levels($entityType, $amount) as $rule) {
            if ($rule->level === $level) {
                return array_values($rule->approver_roles);
            }
        }

        return [];
    }

    /**
     * Decide whether $approver may approve level $level of an item prepared by $preparerId.
     *
     * @throws MakerCheckerViolation|AuthorizationException
     */
    /** @param  list<int>  $priorApproverIds  people who already approved earlier levels of this item */
    public function authorize(User $approver, string $entityType, Money $amount, int $level, ?int $preparerId, ?string $overrideReason = null, array $priorApproverIds = []): ApprovalDecision
    {
        if (in_array($approver->id, $priorApproverIds, true)) {
            throw new MakerCheckerViolation('You already approved an earlier level of this item; another approver is needed.');
        }
        $levels = $this->levels($entityType, $amount);
        if ($levels === []) {
            throw new AuthorizationException("No approval matrix configured for {$entityType}.");
        }
        $rule = collect($levels)->first(fn (ApprovalMatrixRule $r): bool => $r->level === $level)
            ?? throw new AuthorizationException("Level {$level} does not apply to this amount.");
        $final = $rule->level === end($levels)->level;

        $override = false;
        if ($preparerId !== null && $preparerId === $approver->id) {
            if (! $approver->isOwner() || trim((string) $overrideReason) === '') {
                throw new MakerCheckerViolation;
            }
            $override = true;
        }

        $codes = array_map(fn (RoleCode $r) => $r->value, $approver->roleCodes());
        if (array_intersect($rule->approver_roles, $codes) !== []) {
            return new ApprovalDecision($rule->level, $final, null, $override, $override ? $overrideReason : null);
        }

        $delegation = $this->delegationFor($approver, $entityType, $amount, $rule->approver_roles);
        if ($delegation !== null) {
            // A delegate may never approve something the delegator prepared on their behalf either.
            if ($preparerId !== null && $delegation->delegator_id === $preparerId) {
                throw new MakerCheckerViolation('The item was prepared by the person who delegated to you; it needs another approver.');
            }

            return new ApprovalDecision($rule->level, $final, $delegation->id, false, null);
        }

        throw new AuthorizationException('You are not an approver for this item at level '.$rule->level.' (requires '.implode(' or ', $rule->approver_roles).').');
    }

    /** @return list<int> */
    public function priorApprovers(string $entityType, int $entityId): array
    {
        return DB::table('approval_steps')->where('entity_type', $entityType)->where('entity_id', $entityId)->pluck('approver_id')->map(fn ($v): int => (int) $v)->all();
    }

    public function record(string $entityType, int $entityId, ApprovalDecision $decision, User $approver): void
    {
        DB::table('approval_steps')->insert([
            'entity_type' => $entityType, 'entity_id' => $entityId, 'level' => $decision->level, 'approver_id' => $approver->id,
            'delegation_id' => $decision->delegationId, 'owner_override' => $decision->ownerOverride, 'reason' => $decision->overrideReason, 'decided_at' => now(),
        ]);
    }

    /** Forget recorded steps (an item returned to draft starts its approval chain again). */
    public function reset(string $entityType, int $entityId): void
    {
        DB::table('approval_steps')->where('entity_type', $entityType)->where('entity_id', $entityId)->delete();
    }

    /** Record an owner override in the audit trail (called by the module after it stores the approval). */
    public function auditOverride(Model $entity, User $owner, string $reason): void
    {
        Audit::log('owner_override', $entity, null, null, ['maker_checker' => 'overridden'], $reason, $owner);
    }

    /** @param  list<string>  $roles */
    private function delegationFor(User $delegate, string $entityType, Money $amount, array $roles): ?ApprovalDelegation
    {
        return ApprovalDelegation::query()
            ->where('delegate_id', $delegate->id)->where('entity_type', $entityType)->activeAt()
            ->with('delegator.roles')->get()
            ->first(function (ApprovalDelegation $d) use ($entityType, $amount, $roles): bool {
                $delegatorRoles = array_map(fn (RoleCode $r) => $r->value, $d->delegator->roleCodes());

                return $d->covers($entityType, $amount) && array_intersect($roles, $delegatorRoles) !== [] && $d->delegator->isActive();
            });
    }
}
