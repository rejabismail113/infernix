<?php

declare(strict_types=1);

namespace App\Modules\Csr\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * In-kind contribution (goods / services / venue) at an ESTIMATED value. Never part of cash spend (M08-03).
 *
 * @see SRS M08-03
 *
 * @property int $id
 * @property int $programme_id
 * @property string $item
 * @property string $quantity
 * @property Money $estimated_value
 * @property string $source
 * @property string|null $valuation_basis
 * @property int $created_by
 */
final class CsrInKindContribution extends Model
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_value' => MoneyCast::class];
    }
}
