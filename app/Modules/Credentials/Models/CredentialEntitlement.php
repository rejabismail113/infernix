<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Vault entitlement grant (who may reveal / use a credential), revoked on offboarding (M13-04).
 *
 * @see SRS M13-03, M13-04, WF-07
 *
 * @property int $id
 * @property int $credential_id
 * @property int $user_id
 * @property int|null $request_id
 * @property int $granted_by
 * @property Carbon $granted_at
 * @property Carbon|null $expires_on
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoke_reason
 * @property string $vault_sync
 */
final class CredentialEntitlement extends Model
{
    use Auditable;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'expires_on' => 'date', 'revoked_at' => 'datetime', 'user_id' => 'integer'];
    }
}
