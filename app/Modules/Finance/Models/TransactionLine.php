<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $transaction_id
 * @property int $line_no
 * @property string $description
 * @property Money $amount
 * @property int|null $category_id
 * @property int|null $account_id
 * @property int|null $cost_centre_id
 * @property int|null $project_id
 */
final class TransactionLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['transaction_id', 'line_no', 'description', 'amount', 'category_id', 'account_id', 'cost_centre_id', 'project_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }
}
