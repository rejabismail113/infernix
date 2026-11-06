<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Money\MoneyCast;
use App\Modules\Finance\Enums\CapitalizationClass;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $bill_id
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property Money $unit_price
 * @property Money $amount
 * @property int|null $category_id
 * @property CapitalizationClass $capitalization_class
 * @property int|null $project_id
 * @property int|null $activity_id
 */
final class BillLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['bill_id', 'line_no', 'description', 'quantity', 'unit_price', 'amount', 'category_id', 'capitalization_class', 'project_id', 'activity_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_price' => MoneyCast::class, 'amount' => MoneyCast::class, 'capitalization_class' => CapitalizationClass::class];
    }
}
