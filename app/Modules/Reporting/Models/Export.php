<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One Export Centre run: the file itself is a Document Centre document (private disk, signed download).
 * Only the requester sees their exports (record scope own).
 *
 * @see SRS M11-05, §8.1
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property string $report_key
 * @property string $format
 * @property array<string, mixed> $filters
 * @property int $row_count
 * @property int|null $document_id
 * @property Carbon $created_at
 */
final class Export extends Model implements PermissionResource
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'report_key', 'format', 'filters', 'row_count', 'document_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array', 'row_count' => 'integer', 'created_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'exports';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }
}
