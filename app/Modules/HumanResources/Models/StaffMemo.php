<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Memo inbox entry: a memo / letter issued by M03 to this staff member.
 *
 * @see SRS M04 (Memo Inbox), M03-06
 *
 * @property int $id
 * @property int $staff_profile_id
 * @property int $commercial_document_id
 * @property string $subject
 * @property Carbon $delivered_at
 * @property Carbon|null $read_at
 */
final class StaffMemo extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['staff_profile_id', 'commercial_document_id', 'subject', 'delivered_at', 'read_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
