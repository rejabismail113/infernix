<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\FiscalPeriod;
use App\Modules\Finance\Enums\JournalStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Created ONLY by PostingService (architecture test). Posted journals are immutable at DB level.
 * Not Auditable via the trait: PostingService writes explicit 'post'/'reverse' audit events.
 *
 * @see SRS M01-06, M01-08
 *
 * @property int $id
 * @property int $company_id
 * @property string $journal_no
 * @property Carbon $journal_date
 * @property Carbon $posting_date
 * @property int $fiscal_period_id
 * @property string $source_type
 * @property int|null $source_id
 * @property JournalStatus $status
 * @property int|null $reversal_of_id
 * @property int|null $reversed_by_id
 * @property string|null $memo
 * @property int $created_by
 * @property int|null $posted_by
 * @property Carbon|null $posted_at
 * @property-read Collection<int, JournalLine> $lines
 */
final class Journal extends Model implements PermissionResource
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['journal_date' => 'date', 'posting_date' => 'date', 'status' => JournalStatus::class, 'posted_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'journals';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<FiscalPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return BelongsTo<Journal, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
