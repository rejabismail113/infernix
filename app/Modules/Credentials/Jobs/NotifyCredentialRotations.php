<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Jobs;

use App\Kernel\Identity\Models\User;
use App\Kernel\Notifications\NotificationService;
use App\Modules\Credentials\Models\CredentialEntitlement;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Services\AccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Daily: rotation-due and expiry reminders to the credential owner and Owner role (rule CredentialRotationDue),
 * and expiry of time-boxed entitlements. Notifications carry the system name only — never a secret (M13-05).
 *
 * @see SRS M13-04, M13-05
 */
final class NotifyCredentialRotations implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(NotificationService $notifications, AccessService $access): void
    {
        CredentialMetadata::query()->whereIn('status', ['active', 'rotating'])->where(fn ($q) => $q->whereNotNull('rotation_due')->orWhereNotNull('expiry_date'))->each(function (CredentialMetadata $c) use ($notifications): void {
            foreach (['rotation_due' => 'rotation is due', 'expiry_date' => 'expires'] as $field => $what) {
                $date = $c->{$field};
                if ($date === null || $date->gt(today()->addDays(30))) {
                    continue;
                }
                $days = (int) today()->diffInDays($date, false);
                $notifications->notifyBefore('CredentialRotationDue', max(0, $days), "credential:{$c->id}:{$field}:{$date->toDateString()}", "{$c->system_name}: {$what} ".($days >= 0 ? "in {$days} days" : 'overdue'),
                    "{$c->credential_no} {$c->system_name} ({$c->environment}) {$what} on {$date->toDateString()}. Rotate it in the vault, then record the rotation.", route('credentials.credentials.show', $c->id), null, [$c->owner_id]);
            }
        });
        $system = User::query()->whereHas('roles', fn ($r) => $r->where('code', 'OWN'))->orderBy('id')->first();
        if ($system !== null) {
            CredentialEntitlement::query()->whereNull('revoked_at')->whereNotNull('expires_on')->whereDate('expires_on', '<', today())->get()
                ->each(fn (CredentialEntitlement $e) => $access->revoke($e, $system, 'Time-boxed access expired'));
        }
    }
}
