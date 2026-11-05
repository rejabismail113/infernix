<?php

declare(strict_types=1);

namespace App\Kernel\Approval;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $approval_matrix_id
 * @property Money $min_amount
 * @property Money|null $max_amount
 * @property int $level
 * @property list<string> $approver_roles
 */
final class ApprovalMatrixRule extends Model
{
    /** @var list<string> */
    protected $fillable = ['approval_matrix_id', 'min_amount', 'max_amount', 'level', 'approver_roles'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['min_amount' => MoneyCast::class, 'max_amount' => MoneyCast::class, 'level' => 'integer', 'approver_roles' => 'array'];
    }

    public function covers(Money $amount): bool
    {
        return $amount->isGreaterThanOrEqualTo($this->min_amount) && ($this->max_amount === null || $amount->isLessThanOrEqualTo($this->max_amount));
    }
}
