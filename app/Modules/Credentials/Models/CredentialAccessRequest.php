<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Access request: pending → approved (by the resource owner, never the requester) | rejected | withdrawn (WF-07).
 *
 * @see SRS M13-04, WF-07
 *
 * @property int $id
 * @property int $credential_id
 * @property int $requester_id
 * @property string $justification
 * @property Carbon|null $access_until
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property-read CredentialMetadata $credential
 */
final class CredentialAccessRequest extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_until' => 'date', 'decided_at' => 'datetime', 'requester_id' => 'integer'];
    }

    /** @return BelongsTo<CredentialMetadata, $this> */
    public function credential(): BelongsTo
    {
        return $this->belongsTo(CredentialMetadata::class, 'credential_id');
    }
}
