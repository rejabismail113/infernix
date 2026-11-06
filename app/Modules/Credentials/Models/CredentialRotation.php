<?php

declare(strict_types=1);

namespace App\Modules\Credentials\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Rotation log entry (the new secret is set in the vault; only the date is recorded here — M13-04).
 *
 * @see SRS M13-04
 *
 * @property int $id
 * @property int $credential_id
 * @property Carbon $rotated_on
 * @property int $rotated_by
 * @property string|null $note
 */
final class CredentialRotation extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rotated_on' => 'date'];
    }
}
