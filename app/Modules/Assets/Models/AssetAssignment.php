<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Custodian / location history: assignment, handover and return (M05-03).
 *
 * @see SRS M05-03
 *
 * @property int $id
 * @property int $asset_id
 * @property int|null $staff_profile_id
 * @property string|null $location
 * @property Carbon $assigned_on
 * @property Carbon|null $expected_return_on
 * @property Carbon|null $returned_on
 * @property string|null $condition_out
 * @property string|null $condition_in
 * @property string|null $note
 * @property int $assigned_by
 * @property int|null $returned_by
 */
final class AssetAssignment extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'expected_return_on' => 'date', 'returned_on' => 'date', 'staff_profile_id' => 'integer'];
    }
}
