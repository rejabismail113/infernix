<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS M06-05
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $retain_months
 * @property string $basis
 * @property string|null $notes
 * @property bool $active
 */
final class RetentionRule extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'retain_months', 'basis', 'notes', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retain_months' => 'integer', 'active' => 'boolean'];
    }
}
