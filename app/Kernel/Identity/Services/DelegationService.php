<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\ApprovalDelegation;
use App\Kernel\Identity\Models\User;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Delegations are versioned: changing one inserts a new version that supersedes the old row.
 *
 * @see SRS M12-03, §2.1
 */
final class DelegationService
{
    /**
     * @param  array{delegator_id:int, delegate_id:int, entity_type:string, max_amount:Money|string|null, valid_from:Carbon, valid_to:Carbon, reason:string}  $data
     */
    public function create(array $data, User $actor): ApprovalDelegation
    {
        return DB::transaction(fn () => ApprovalDelegation::query()->create([...$data, 'version' => 1, 'created_by' => $actor->id]));
    }

    /**
     * @param  array{max_amount?:Money|string|null, valid_from?:Carbon, valid_to?:Carbon, reason?:string, delegate_id?:int, entity_type?:string}  $changes
     */
    public function revise(ApprovalDelegation $current, array $changes, User $actor): ApprovalDelegation
    {
        if (($changes['reason'] ?? '') === '') {
            throw new InvalidArgumentException('A reason is required to revise a delegation.');
        }

        return DB::transaction(function () use ($current, $changes, $actor): ApprovalDelegation {
            $new = ApprovalDelegation::query()->create([
                'delegator_id' => $current->delegator_id,
                'delegate_id' => $changes['delegate_id'] ?? $current->delegate_id,
                'entity_type' => $changes['entity_type'] ?? $current->entity_type,
                'max_amount' => array_key_exists('max_amount', $changes) ? $changes['max_amount'] : $current->max_amount,
                'valid_from' => $changes['valid_from'] ?? $current->valid_from,
                'valid_to' => $changes['valid_to'] ?? $current->valid_to,
                'reason' => $changes['reason'],
                'version' => $current->version + 1,
                'supersedes_id' => $current->id,
                'created_by' => $actor->id,
            ]);
            Audit::log('permission_change', $new, null, ['delegation_id' => $current->id, 'version' => $current->version], ['version' => $new->version], $changes['reason'], $actor);

            return $new;
        });
    }

    public function revoke(ApprovalDelegation $delegation, string $reason, User $actor): void
    {
        DB::transaction(function () use ($delegation, $reason, $actor): void {
            $delegation->forceFill(['revoked_at' => now()])->save();
            Audit::log('permission_change', $delegation, null, ['revoked_at' => null], ['revoked_at' => now()->toIso8601String()], $reason, $actor);
        });
    }

    /** Can $delegate approve an $entityType item of $amount on behalf of someone right now? */
    public function canApprove(User $delegate, string $entityType, ?Money $amount, ?Carbon $at = null): bool
    {
        return $this->activeFor($delegate, $entityType, $at)
            ->contains(fn (ApprovalDelegation $d): bool => $d->covers($entityType, $amount, $at));
    }

    /** @return Collection<int, ApprovalDelegation> */
    public function activeFor(User $delegate, string $entityType, ?Carbon $at = null)
    {
        return ApprovalDelegation::query()
            ->where('delegate_id', $delegate->id)
            ->where('entity_type', $entityType)
            ->activeAt($at)
            ->get();
    }
}
