<?php

declare(strict_types=1);

namespace App\Modules\Finance\Posting;

use App\Kernel\Money\Money;
use Brick\Money\Money as BrickMoney;

/** One side of a journal line. Exactly one of debit/credit is non-zero. */
final class JournalLineDraft
{
    public function __construct(
        public readonly int $accountId,
        public readonly BrickMoney $debit,
        public readonly BrickMoney $credit,
        public readonly ?string $description = null,
        public readonly ?int $projectId = null,
        public readonly ?int $costCentreId = null,
        public readonly ?int $activityId = null,
    ) {}

    public static function debit(int $accountId, BrickMoney $amount, ?string $description = null, ?int $projectId = null, ?int $costCentreId = null, ?int $activityId = null): self
    {
        return new self($accountId, $amount, Money::zero(), $description, $projectId, $costCentreId, $activityId);
    }

    public static function credit(int $accountId, BrickMoney $amount, ?string $description = null, ?int $projectId = null, ?int $costCentreId = null, ?int $activityId = null): self
    {
        return new self($accountId, Money::zero(), $amount, $description, $projectId, $costCentreId, $activityId);
    }
}
