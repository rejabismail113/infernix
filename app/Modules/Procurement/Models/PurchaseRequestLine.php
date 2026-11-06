<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @see SRS M10-02
 *
 * @property int $id
 * @property int $purchase_request_id
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property Money $estimated_unit_price
 * @property Money $estimated_amount
 * @property string $capitalization_class
 */
final class PurchaseRequestLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_unit_price' => MoneyCast::class, 'estimated_amount' => MoneyCast::class];
    }
}
