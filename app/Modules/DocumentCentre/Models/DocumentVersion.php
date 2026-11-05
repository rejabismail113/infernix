<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Documents\ScanStatus;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable file version (DB trigger). storage_key is server-side only and never sent to the client.
 *
 * @see SRS M06-03, G-07, §6.3
 *
 * @property int $id
 * @property int $document_id
 * @property int $version_no
 * @property string $file_name
 * @property string $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $content_hash
 * @property string $storage_key
 * @property ScanStatus $scan_status
 * @property string|null $scanner
 * @property int $uploaded_by
 * @property Carbon $created_at
 * @property-read Document $document
 * @property-read User $uploader
 */
final class DocumentVersion extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['document_id', 'version_no', 'file_name', 'extension', 'mime_type', 'size_bytes', 'content_hash', 'storage_key', 'scan_status', 'scanner', 'uploaded_by'];

    /** @var list<string> */
    protected $hidden = ['storage_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version_no' => 'integer', 'size_bytes' => 'integer', 'scan_status' => ScanStatus::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
