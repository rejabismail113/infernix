<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * "Record X of type T requires a document linked with link_type L" — drives the missing-evidence report.
 *
 * @see SRS M06 outputs (Missing required evidence), M01-03, M01-09
 *
 * @property int $id
 * @property string $entity_type
 * @property string $condition
 * @property string $link_type
 * @property string $description
 * @property bool $active
 * @property string|null $notes
 */
final class RequiredEvidenceRule extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['entity_type', 'condition', 'link_type', 'description', 'active', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
