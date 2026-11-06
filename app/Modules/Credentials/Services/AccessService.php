<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use App\Modules\Credentials\Models\CredentialAccessRequest;
use App\Modules\Credentials\Models\CredentialEntitlement;
use App\Modules\Credentials\Models\CredentialMetadata;
use Illuminate\Support\Facades\DB;

/**
 * WF-07 credential access: request → resource-owner approval (never the requester) → entitlement grant
 * (mirrored to the vault where supported) → audit → revoke (manually or automatically at offboarding).
 *
 * @see SRS M13-03, M13-04, WF-07
 */
final class AccessService
{
    public function __construct(private readonly VaultClient $vault) {}

    public function request(CredentialMetadata $c, User $requester, string $justification, ?string $until): CredentialAccessRequest
    {
        if ($c->hasActiveEntitlement($requester)) {
            throw new CredentialRejected('You already have access to this credential.');
        }
        if (CredentialAccessRequest::query()->where('credential_id', $c->id)->where('requester_id', $requester->id)->where('status', 'pending')->exists()) {
            throw new CredentialRejected('You already have a pending request for this credential.');
        }
        $r = CredentialAccessRequest::query()->create(['credential_id' => $c->id, 'requester_id' => $requester->id, 'justification' => $justification, 'access_until' => $until, 'status' => 'pending']);
        Audit::log('access_request', $c, null, null, ['request_id' => $r->id, 'until' => $until], $justification, $requester);

        return $r;
    }

    public function decide(CredentialAccessRequest $request, bool $approve, User $decider, ?string $note): ?CredentialEntitlement
    {
        return DB::transaction(function () use ($request, $approve, $decider, $note): ?CredentialEntitlement {
            /** @var CredentialAccessRequest $r */
            $r = CredentialAccessRequest::query()->lockForUpdate()->with('credential')->findOrFail($request->id);
            if ($r->status !== 'pending') {
                throw new CredentialRejected('This request is already decided.');
            }
            if ($r->requester_id === $decider->id) {
                throw new CredentialRejected('You cannot approve your own access request (maker-checker).');
            }
            $r->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $decider->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            Audit::log($approve ? 'access_approve' : 'access_reject', $r->credential, null, null, ['request_id' => $r->id, 'requester_id' => $r->requester_id], $note, $decider);
            if (! $approve) {
                return null;
            }

            return $this->grant($r->credential, User::query()->findOrFail($r->requester_id), $decider, $r->id, $r->access_until?->toDateString());
        });
    }

    public function grant(CredentialMetadata $c, User $user, User $grantor, ?int $requestId = null, ?string $until = null): CredentialEntitlement
    {
        $synced = $c->vault_item_reference !== null && $this->vault->isConfigured() ? ($this->vault->grant($c->vault_item_reference, (string) $user->email) ? 'synced' : 'not_applicable') : 'not_applicable';
        $e = CredentialEntitlement::query()->create(['credential_id' => $c->id, 'user_id' => $user->id, 'request_id' => $requestId, 'granted_by' => $grantor->id, 'granted_at' => now(),
            'expires_on' => $until, 'vault_sync' => $synced]);
        Audit::log('entitlement_grant', $c, null, null, ['user_id' => $user->id, 'until' => $until, 'vault_sync' => $synced], null, $grantor);

        return $e;
    }

    public function revoke(CredentialEntitlement $e, User $actor, string $reason): void
    {
        if ($e->revoked_at !== null) {
            return;
        }
        $c = CredentialMetadata::query()->findOrFail($e->credential_id);
        $user = User::query()->find($e->user_id);
        if ($c->vault_item_reference !== null && $this->vault->isConfigured() && $user !== null) {
            $this->vault->revoke($c->vault_item_reference, (string) $user->email);
        }
        $e->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
        Audit::log('entitlement_revoke', $c, null, ['user_id' => $e->user_id], null, $reason, $actor);
    }

    /** Offboarding: revoke every active entitlement of the user. @return int revoked */
    public function revokeAllFor(int $userId, User $actor, string $reason): int
    {
        $n = 0;
        foreach (CredentialEntitlement::query()->where('user_id', $userId)->whereNull('revoked_at')->get() as $e) {
            $this->revoke($e, $actor, $reason);
            $n++;
        }

        return $n;
    }
}
