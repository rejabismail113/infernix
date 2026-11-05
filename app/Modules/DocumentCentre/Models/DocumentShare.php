<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Explicit per-document share (view only), optionally time-limited. Audited.
 *
 * @see SRS M02-07, M06 (Sharing)
 *
 * @property int $id
 * @property int $document_id
 * @property int $user_id
 * @property string $permission
 * @property Carbon|null $expires_at
 * @property int $shared_by
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class DocumentShare extends Model
{
    use Auditable;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['document_id', 'user_id', 'permission', 'expires_at', 'shared_by', 'reason'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param  Builder<DocumentShare>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }
}
