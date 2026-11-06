<?php

declare(strict_types=1);

namespace App\Kernel\Notifications;

use App\Kernel\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Dispatches a reminder to the recipients configured in notification_rules (M12 master data): target roles /
 * users, channel, lead days. Each (rule, recipient, subject key) is sent at most once (notification_dispatches); daily reminders put the date in the key.
 *
 * @see SRS G-05, M12-05
 */
final class NotificationService
{
    /**
     * @param  string  $dedupeKey  stable subject key, e.g. "invoice:12"
     * @param  list<string>|null  $eligibleRoles  further restrict recipients (e.g. approver roles for the level)
     * @param  list<int>  $excludeUserIds  never notify these (e.g. the preparer of an item awaiting approval)
     * @return int notifications sent
     */
    public function notify(string $event, int $daysPastOrBefore, string $dedupeKey, string $title, string $body, string $url, ?array $eligibleRoles = null, array $excludeUserIds = []): int
    {
        $sent = 0;
        $rules = DB::table('notification_rules')->where('event', $event)->where('active', true)->where('lead_days', '<=', max(0, $daysPastOrBefore))->orderByDesc('lead_days')->get();
        foreach ($rules as $rule) {
            /** @var list<string> $roles */
            $roles = json_decode((string) ($rule->target_roles ?? '[]'), true) ?: [];
            /** @var list<int> $userIds */
            $userIds = json_decode((string) ($rule->target_user_ids ?? '[]'), true) ?: [];
            if ($eligibleRoles !== null) {
                $roles = array_values(array_intersect($roles, $eligibleRoles));
            }
            $recipients = User::query()->whereNull('deactivated_at')
                ->where(fn ($q) => $q->whereIn('id', $userIds)->orWhereHas('roles', fn ($r) => $r->whereIn('code', $roles === [] ? ['__none__'] : $roles)))
                ->when($excludeUserIds !== [], fn ($q) => $q->whereNotIn('id', $excludeUserIds))->get();
            $channels = match ($rule->channel) {
                'email' => ['mail'],
                'in_app' => ['database'],
                default => ['mail', 'database'],
            };
            foreach ($recipients as $user) {
                if (! $this->claim((int) $rule->id, $user->id, $dedupeKey)) {
                    continue;
                }
                Notification::send($user, new BusinessNotification($event, $title, $body, $url, $channels));
                $sent++;
            }
            // Only the highest lead-day rule that applies fires today; lower thresholds already fired earlier.
            break;
        }

        return $sent;
    }

    /**
     * Ahead-of-date reminders (renewals, expiries, rotations): with rules at e.g. 90/60/30 lead days and
     * $daysUntil = 45, the 60-day rule fires — once per threshold (the threshold is part of the dedupe key) —
     * so a renewal 45 days out that was missed at 90 still gets its 60-day reminder, and 30 later.
     *
     * @param  list<string>|null  $eligibleRoles
     * @param  list<int>  $extraUserIds  e.g. the license owner / certificate holder
     * @return int notifications sent
     */
    public function notifyBefore(string $event, int $daysUntil, string $subjectKey, string $title, string $body, string $url, ?array $eligibleRoles = null, array $extraUserIds = []): int
    {
        $rule = DB::table('notification_rules')->where('event', $event)->where('active', true)->where('lead_days', '>=', max(0, $daysUntil))->orderBy('lead_days')->first();
        if ($rule === null) {
            return 0;
        }
        /** @var list<string> $roles */
        $roles = json_decode((string) ($rule->target_roles ?? '[]'), true) ?: [];
        /** @var list<int> $userIds */
        $userIds = array_merge(json_decode((string) ($rule->target_user_ids ?? '[]'), true) ?: [], $extraUserIds);
        if ($eligibleRoles !== null) {
            $roles = array_values(array_intersect($roles, $eligibleRoles));
        }
        $recipients = User::query()->whereNull('deactivated_at')
            ->where(fn ($q) => $q->whereIn('id', $userIds === [] ? [0] : $userIds)->orWhereHas('roles', fn ($r) => $r->whereIn('code', $roles === [] ? ['__none__'] : $roles)))->get();
        $channels = match ($rule->channel) {
            'email' => ['mail'],
            'in_app' => ['database'],
            default => ['mail', 'database'],
        };
        $sent = 0;
        foreach ($recipients as $user) {
            if ($this->claim((int) $rule->id, $user->id, "{$subjectKey}:L{$rule->lead_days}")) {
                Notification::send($user, new BusinessNotification($event, $title, $body, $url, $channels));
                $sent++;
            }
        }

        return $sent;
    }

    /** Lead-day threshold that applies today for an ahead-of-date reminder, or null when none applies yet. */
    public function thresholdFor(string $event, int $daysUntil): ?int
    {
        $lead = DB::table('notification_rules')->where('event', $event)->where('active', true)->where('lead_days', '>=', max(0, $daysUntil))->min('lead_days');

        return $lead === null ? null : (int) $lead;
    }

    private function claim(int $ruleId, int $userId, string $key): bool
    {
        try {
            return DB::table('notification_dispatches')->insertOrIgnore([
                'notification_rule_id' => $ruleId, 'user_id' => $userId, 'dedupe_key' => mb_substr($key, 0, 160), 'dispatched_on' => today()->toDateString(), 'created_at' => now(),
            ]) === 1;
        } catch (QueryException) {
            return false;
        }
    }
}
