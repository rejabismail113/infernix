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
