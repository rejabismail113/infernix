<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Money\Money;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;

/**
 * Line arithmetic for quotations / invoices / credit notes, in decimals only (never floats, SRS §6.3):
 * net = round(qty × unit price, 2) − discount; tax = round(net × rate%, 2, half-up); total = net + tax.
 *
 * @see SRS M03-01, M03-03
 */
final class DocumentMath
{
    /** @return array{net: BrickMoney, tax: BrickMoney, total: BrickMoney, gross: BrickMoney} */
    public static function line(string $quantity, string $unitPrice, string $discount, string $taxRate): array
    {
        $qty = BigDecimal::of($quantity);
        if (! $qty->isPositive()) {
            throw new CommercialRejected('Quantity must be greater than zero.');
        }
        $gross = BrickMoney::of($qty->multipliedBy(BigDecimal::of($unitPrice))->toScale(2, RoundingMode::HALF_UP), 'MYR');
        $disc = Money::myr($discount === '' ? '0' : $discount);
        if ($disc->isNegative() || $disc->isGreaterThan($gross)) {
            throw new CommercialRejected('A line discount must be between zero and the line amount.');
        }
        $net = $gross->minus($disc);
        $tax = BrickMoney::of($net->getAmount()->multipliedBy(BigDecimal::of($taxRate))->dividedBy(100, 2, RoundingMode::HALF_UP), 'MYR');

        return ['gross' => $gross, 'net' => $net, 'tax' => $tax, 'total' => $net->plus($tax)];
    }
}
