<?php

declare(strict_types=1);

namespace App\Modules\Csr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS M08-01
 *
 * @property int $id
 * @property int $programme_id
 * @property int|null $staff_profile_id
 * @property string|null $name
 * @property string $hours
 * @property string|null $role
 */
final class CsrVolunteer extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];
}
