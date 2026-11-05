<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Polymorphic link document ↔ business record. entity_type is always a key of the validated
 * DocumentLinkRegistry morph map (never a class name supplied by a client).
 *
 * @see SRS M06-01, §6.1
 *
 * @property int $id
 * @property string $entity_type
 * @property int $entity_id
 * @property int $document_id
 * @property string $link_type
 * @property bool $required_evidence
 * @property int $linked_by
 * @property Carbon $created_at
 */
final class EntityDocumentLink extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['entity_type', 'entity_id', 'document_id', 'link_type', 'required_evidence', 'linked_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entity_id' => 'integer', 'required_evidence' => 'boolean', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return MorphTo<Model, $this> */
    public function entity(): MorphTo
    {
        return $this->morphTo('entity', 'entity_type', 'entity_id');
    }
}
