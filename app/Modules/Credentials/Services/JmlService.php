<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Models\JmlChecklist;

/**
 * Joiner / mover / leaver checklists (M13-04). A leaver's entitlements are revoked AUTOMATICALLY at once; the
 * checklist then tracks the manual steps (rotate shared secrets they knew, reassign credentials they own, …).
 * Checklist wording is PLACEHOLDER pending the owner's IT policy (A-75).
 *
 * @see SRS M13-04, WF-07
 */
final class JmlService
{
    public function __construct(private readonly AccessService $access) {}

    public function open(string $kind, int $staffProfileId, ?int $userId, string $trigger, User $actor): JmlChecklist
    {
        $items = match ($kind) {
            'joiner' => [['key' => 'account', 'label' => 'Create user account with the least-privilege role'], ['key' => 'mfa', 'label' => 'Confirm MFA is enrolled'],
                ['key' => 'access', 'label' => 'Request only the credentials needed (access requests)'], ['key' => 'nda', 'label' => 'NDA / acceptable-use acknowledged']],
            'mover' => [['key' => 'review', 'label' => 'Review existing entitlements against the new role'], ['key' => 'revoke', 'label' => 'Revoke access no longer needed'],
                ['key' => 'request', 'label' => 'Request access for the new responsibilities']],
            default => [['key' => 'revoked', 'label' => 'Revoke all credential entitlements', 'auto' => true], ['key' => 'owned', 'label' => 'Reassign credentials owned by the leaver'],
                ['key' => 'rotate', 'label' => 'Rotate shared / service secrets the leaver could use'], ['key' => 'account', 'label' => 'Deactivate the user account'],
                ['key' => 'assets', 'label' => 'Collect company assets in custody']],
        };
        $revoked = 0;
        if ($kind === 'leaver' && $userId !== null) {
            $revoked = $this->access->revokeAllFor($userId, $actor, 'Offboarding (staff marked as leaver)');
        }
        $items = array_map(fn (array $i): array => $i + ['done' => ($i['auto'] ?? false) === true, 'done_by' => ($i['auto'] ?? false) ? $actor->id : null, 'done_at' => ($i['auto'] ?? false) ? now()->toIso8601String() : null], $items);
        if ($kind === 'leaver' && $userId !== null) {
            foreach (CredentialMetadata::query()->where('owner_id', $userId)->whereIn('status', ['active', 'rotating'])->get() as $c) {
                $items[] = ['key' => "owned:{$c->id}", 'label' => "Reassign owner of {$c->credential_no} {$c->system_name}", 'done' => false, 'done_by' => null, 'done_at' => null];
            }
        }
        $checklist = JmlChecklist::query()->create(['kind' => $kind, 'staff_profile_id' => $staffProfileId, 'user_id' => $userId, 'trigger' => mb_substr($trigger, 0, 60), 'items' => $items, 'status' => 'open']);
        Audit::log('jml_open', $checklist, null, null, ['kind' => $kind, 'entitlements_revoked' => $revoked], $trigger, $actor);

        return $checklist;
    }

    public function tick(JmlChecklist $checklist, string $key, User $actor): void
    {
        $items = $checklist->items;
        $found = false;
        foreach ($items as $i => $item) {
            if ($item['key'] === $key) {
                $items[$i] = ['done' => true, 'done_by' => $actor->id, 'done_at' => now()->toIso8601String()] + $item;
                $items[$i]['done'] = true;
                $found = true;
            }
        }
        if (! $found) {
            throw new CredentialRejected('Unknown checklist item.');
        }
        $complete = collect($items)->every(fn (array $i): bool => $i['done']);
        $checklist->forceFill(['items' => $items, 'status' => $complete ? 'completed' : 'open', 'completed_by' => $complete ? $actor->id : null, 'completed_at' => $complete ? now() : null])->save();
        Audit::log('jml_tick', $checklist, null, null, ['item' => $key], null, $actor);
    }
}
