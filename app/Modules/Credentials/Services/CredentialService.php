<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Crm\Contracts\ProjectDirectory;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Enums\RecordScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Numbering\Services\NumberingService;
use App\Kernel\Search\SearchIndexer;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Kernel\Secrets\SecretRedactor;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Models\CredentialRotation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Credential register (M13): metadata + vault reference ONLY. Any text that looks like a secret is refused at
 * the door (M13-02); the secret is revealed only from the vault, to an entitled user, after re-authentication,
 * audited, and never stored, logged or cached server-side (M13-03).
 *
 * @see SRS M13-01, M13-02, M13-03, M13-04, M13-06, UAT-08
 */
final class CredentialService
{
    private const TEXT_FIELDS = ['system_name', 'url', 'username_reference', 'vault_item_reference', 'shared_justification', 'notes'];

    public function __construct(
        private readonly NumberingService $numbering,
        private readonly VaultClient $vault,
        private readonly SecretRedactor $redactor,
        private readonly PermissionService $permissions,
        private readonly ProjectDirectory $projects,
        private readonly SearchIndexer $search,
    ) {}

    /** @return Builder<CredentialMetadata> credentials the user may see (client segregation in SQL, M13-06) */
    public function visibleQuery(User $user): Builder
    {
        $scope = $this->permissions->scopeFor($user, 'credentials', PermissionAction::View);
        $q = CredentialMetadata::query();
        if ($scope === RecordScope::All) {
            return $q;
        }
        if ($scope === RecordScope::None) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function (Builder $w) use ($user, $scope): void {
            $w->where('owner_id', $user->id)->orWhere('created_by', $user->id);
            if ($scope === RecordScope::Assigned) {
                $w->orWhereHas('entitlements', fn ($e) => $e->where('user_id', $user->id)->whereNull('revoked_at'));
                $projects = $this->projects->visibleProjectIds($user);
                $clients = $this->projects->visibleClientIds($user);
                if ($projects === '*') {
                    $w->orWhereNotNull('project_id');
                } elseif ($projects !== []) {
                    $w->orWhereIn('project_id', $projects);
                }
                if ($clients === '*') {
                    $w->orWhere(fn ($x) => $x->whereNull('project_id')->whereNotNull('client_id'));
                } elseif ($clients !== []) {
                    $w->orWhere(fn ($x) => $x->whereNull('project_id')->whereIn('client_id', $clients));
                }
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    public function save(?CredentialMetadata $c, array $data, User $actor): CredentialMetadata
    {
        $this->refuseSecrets($data);
        if (($data['category'] ?? null) === 'client' && empty($data['client_id']) && empty($data['project_id'])) {
            throw new CredentialRejected('Client credentials must be tied to their client (segregation, M13-06).');
        }
        if (! empty($data['project_id']) && empty($data['client_id'])) {
            $data['client_id'] = $this->projects->snapshot((int) $data['project_id'])['client_id'] ?? null;
        }
        $shared = (bool) ($data['is_shared_service_account'] ?? false);
        if ($shared && trim((string) ($data['shared_justification'] ?? '')) === '') {
            throw new CredentialRejected('Shared service accounts are discouraged (policy): record why one is needed and who uses it.');
        }

        return DB::transaction(function () use ($c, $data, $actor, $shared): CredentialMetadata {
            $fields = array_intersect_key($data, array_flip(['system_name', 'category', 'environment', 'url', 'username_reference', 'owner_id', 'project_id', 'client_id', 'vault_provider',
                'vault_item_reference', 'sensitivity', 'rotation_interval_days', 'last_rotated_on', 'expiry_date', 'notes', 'shared_justification'])) + ['is_shared_service_account' => $shared];
            if (! $shared) {
                $fields['shared_justification'] = null;
            }
            $fields['rotation_due'] = $this->rotationDue($fields['last_rotated_on'] ?? $c?->last_rotated_on?->toDateString(), isset($fields['rotation_interval_days']) ? (int) $fields['rotation_interval_days'] : $c?->rotation_interval_days);
            if ($c === null) {
                $c = CredentialMetadata::query()->create($fields + ['company_id' => $actor->company_id, 'status' => 'active', 'created_by' => $actor->id, 'owner_id' => $fields['owner_id'] ?? $actor->id,
                    'credential_no' => $this->numbering->allocate((int) $actor->company_id, 'CREDENTIAL', (int) today()->format('Y'))['formatted']]);
            } else {
                $c->forceFill($fields)->save();
            }
            if ($shared && $c->wasChanged('is_shared_service_account')) {
                Audit::log('shared_account_flagged', $c, null, null, ['shared' => true], (string) $c->shared_justification, $actor);
            }
            $this->search->index('credential', $c->id, "{$c->credential_no} {$c->system_name}", "{$c->category} {$c->environment}", 'Credentials');

            return $c;
        });
    }

    public function setStatus(CredentialMetadata $c, string $status, User $actor, ?string $reason = null): void
    {
        if (! in_array($status, ['active', 'rotating', 'revoked', 'retired'], true)) {
            throw new CredentialRejected('Unknown status.');
        }
        $before = $c->status;
        $c->forceFill(['status' => $status])->save();
        Audit::log('status', $c, null, ['status' => $before], ['status' => $status], $reason, $actor);
    }

    /** The secret was rotated IN THE VAULT; record only the date (M13-04). */
    public function recordRotation(CredentialMetadata $c, string $rotatedOn, ?string $note, User $actor): void
    {
        $this->refuseSecrets(['notes' => $note]);
        DB::transaction(function () use ($c, $rotatedOn, $note, $actor): void {
            CredentialRotation::query()->create(['credential_id' => $c->id, 'rotated_on' => $rotatedOn, 'rotated_by' => $actor->id, 'note' => $note, 'created_at' => now()]);
            $c->forceFill(['last_rotated_on' => $rotatedOn, 'rotation_due' => $this->rotationDue($rotatedOn, $c->rotation_interval_days), 'status' => $c->status === 'rotating' ? 'active' : $c->status])->save();
            Audit::log('rotate', $c, null, null, ['rotated_on' => $rotatedOn], null, $actor);
        });
    }

    /**
     * Reveal the secret from the vault. Caller must have passed RequireRecentAuth and the revealSecret policy.
     * Audited WITHOUT the value; the value is returned to the caller only and never stored or logged.
     */
    public function reveal(CredentialMetadata $c, User $actor, string $reason): string
    {
        if (trim($reason) === '') {
            throw new CredentialRejected('A reason is required to reveal a secret.');
        }
        if (! $this->vault->isConfigured() || $c->vault_item_reference === null) {
            throw new CredentialRejected('No vault is configured for this credential; open it in your vault using the reference.');
        }
        Audit::log('reveal', $c, null, null, ['field' => 'vault_secret', 'provider' => $this->vault->provider()], $reason, $actor);

        return $this->vault->reveal($c->vault_item_reference);
    }

    public function deepLink(CredentialMetadata $c): ?string
    {
        return $c->vault_item_reference === null ? null : $this->vault->deepLink($c->vault_item_reference);
    }

    /**
     * M13-02: refuse anything that looks like a password / key / token, and the fake sentinel.
     *
     * @param  array<string, mixed>  $data
     */
    public function refuseSecrets(array $data): void
    {
        foreach (self::TEXT_FIELDS as $field) {
            $v = $data[$field] ?? null;
            if (is_string($v) && $v !== '' && $this->redactor->scrub($v) !== $v) {
                throw new CredentialRejected("The {$field} looks like it contains a secret. Store secrets in the vault; record only the vault item reference here.");
            }
        }
    }

    private function rotationDue(?string $last, ?int $interval): ?string
    {
        if ($interval === null || $interval < 1) {
            return null;
        }

        return Carbon::parse($last ?? today()->toDateString())->addDays($interval)->toDateString();
    }
}
