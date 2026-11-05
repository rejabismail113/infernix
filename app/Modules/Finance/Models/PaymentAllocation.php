<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payment_id
 * @property string $target_type
 * @property int $target_id
 * @property Money $amount
 */
final class PaymentAllocation extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'target_type', 'target_id', 'amount'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'target_id' => 'integer'];
    }
}
