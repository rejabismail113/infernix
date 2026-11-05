<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Explicit record scope grant (project membership, client share, cost centre) used by the `assigned` scope.
 *
 * @see SRS G-02, M02-07
 *
 * @property int $user_id
 * @property string $scope_type
 * @property int $scope_id
 */
final class UserScope extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'scope_type', 'scope_id', 'granted_by', 'granted_at'];
}
