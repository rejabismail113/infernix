<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\StateMachine\IllegalTransition;
use Brick\Money\Money;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared multi-level approval steps for records with `status`, `current_level` and `created_by` columns
 * (purchase requests, asset disposals, …): matrix levels, maker-checker, owner override audit. Callers wrap
 * it in their own DB transaction and row lock.
 *
 * @see SRS M12-03, §2.1, UAT-11
 */
final class ApprovalFlow
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function submit(Model $record, string $entityType, Money $amount, User $actor): void
    {
        if ($record->getAttribute('status') !== 'draft') {
            throw IllegalTransition::for(class_basename($record), (string) $record->getAttribute('status'), 'pending_approval');
        }
        if ($this->approvals->levels($entityType, $amount) === []) {
            throw new IllegalTransition("No approval matrix is configured for {$entityType}.");
        }
        $record->forceFill(['status' => 'pending_approval', 'current_level' => 1])->save();
        Audit::log('submit', $record, null, null, ['status' => 'pending_approval'], null, $actor);
    }

    /**
     * Approve the current level. $onFinal runs (inside the caller's transaction) when the last level is approved.
     *
     * @param  Closure(): void  $onFinal
     * @return bool true when the record is now fully approved
     */
    public function approve(Model $record, string $entityType, Money $amount, User $approver, ?string $overrideReason, Closure $onFinal): bool
    {
        if ($record->getAttribute('status') !== 'pending_approval') {
            throw IllegalTransition::for(class_basename($record), (string) $record->getAttribute('status'), 'approved');
        }
        $id = (int) $record->getKey();
        $level = (int) $record->getAttribute('current_level');
        $decision = $this->approvals->authorize($approver, $entityType, $amount, $level, (int) $record->getAttribute('created_by'), $overrideReason, $this->approvals->priorApprovers($entityType, $id));
        $this->approvals->record($entityType, $id, $decision, $approver);
        if ($decision->ownerOverride) {
            $this->approvals->auditOverride($record, $approver, (string) $overrideReason);
        }
        Audit::log('approve', $record, null, ['level' => $level], ['level' => $decision->level, 'final' => $decision->finalLevel], $overrideReason, $approver);
        if (! $decision->finalLevel) {
            $record->forceFill(['current_level' => $level + 1])->save();

            return false;
        }
        $record->forceFill(['status' => 'approved', 'approved_by' => $approver->id, 'approved_at' => now()])->save();
        $onFinal();

        return true;
    }

    public function reject(Model $record, string $entityType, User $actor, string $reason): void
    {
        if ($record->getAttribute('status') !== 'pending_approval') {
            throw IllegalTransition::for(class_basename($record), (string) $record->getAttribute('status'), 'rejected');
        }
        $record->forceFill(['status' => 'rejected', 'current_level' => 0])->save();
        $this->approvals->reset($entityType, (int) $record->getKey());
        Audit::log('reject', $record, null, null, null, $reason, $actor);
    }
}
