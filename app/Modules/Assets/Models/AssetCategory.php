<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Asset category with default useful life / residual (PLACEHOLDER policy pending accountant sign-off).
 *
 * @see SRS M05-02
 *
 * @property int $id
 * @property string $name
 * @property string $default_class
 * @property int $useful_life_months
 * @property string $residual_percent
 * @property bool $active
 * @property string|null $notes
 */
final class AssetCategory extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'useful_life_months' => 'integer'];
    }
}
