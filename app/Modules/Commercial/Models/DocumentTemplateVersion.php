<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only template version: a Blade view shipped in code + editable settings (A-36).
 *
 * @see SRS M03-07, M03-08
 *
 * @property int $id
 * @property int $document_template_id
 * @property int $version
 * @property string $view_name
 * @property array{accent_color?: string, title?: string, footer_text?: string, default_terms?: string, show_bank_details?: bool} $settings
 * @property string|null $notes
 * @property Carbon $effective_from
 * @property int|null $created_by
 * @property-read DocumentTemplate $template
 */
final class DocumentTemplateVersion extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['document_template_id', 'version', 'view_name', 'settings', 'notes', 'effective_from', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['settings' => 'array', 'effective_from' => 'datetime', 'version' => 'integer'];
    }

    /** @return BelongsTo<DocumentTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }
}
