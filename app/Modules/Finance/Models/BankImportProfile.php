<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CSV column mapping per bank format: {date, description, reference?, amount?} or {debit, credit}.
 *
 * @see SRS M01-07
 *
 * @property int $id
 * @property string $name
 * @property array<string, int|string> $column_mapping
 * @property string $date_format
 * @property bool $has_header
 * @property string|null $notes
 * @property bool $active
 */
final class BankImportProfile extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'column_mapping', 'date_format', 'has_header', 'notes', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['column_mapping' => 'array', 'has_header' => 'boolean', 'active' => 'boolean'];
    }
}
