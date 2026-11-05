<?php

declare(strict_types=1);

namespace App\Kernel\Numbering\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS §6.3, G-03, M03-08
 *
 * @property int $id
 * @property int $company_id
 * @property string $document_type
 * @property int $fiscal_year
 * @property string $prefix
 * @property string $format
 * @property int $next_number
 * @property int $padding
 */
final class NumberingSequence extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'document_type', 'fiscal_year', 'prefix', 'format', 'next_number', 'padding'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'next_number' => 'integer', 'padding' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'numbering';
    }

    public function isOwnedBy(User $user): bool
    {
        return false;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    public function render(int $number): string
    {
        return strtr($this->format, [
            '{prefix}' => $this->prefix,
            '{year}' => (string) $this->fiscal_year,
            '{seq}' => str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT),
        ]);
    }
}
