<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;

/**
 * Money helpers. ALL monetary values are brick/money objects in PHP and NUMERIC(18,4) in Postgres.
 * Floats are forbidden for money (CLAUDE.md rule 1, SRS §6.3) — tests/Architecture enforces it.
 *
 * @see SRS §6.3
 */
final class Money
{
    public const CURRENCY = 'MYR';

    public const DB_SCALE = 4;

    /** Build MYR money from an exact decimal string or integer. Floats are rejected on purpose. */
    public static function myr(string|int $amount): BrickMoney
    {
        return BrickMoney::of($amount, self::CURRENCY, roundingMode: RoundingMode::UNNECESSARY);
    }

    /** Parse a DB NUMERIC(18,4) string into Money (rounded to the currency's 2 dp, half-up). */
    public static function fromDb(string $value): BrickMoney
    {
        return BrickMoney::of($value, self::CURRENCY, roundingMode: RoundingMode::HALF_UP);
    }

    /** Serialise for a NUMERIC(18,4) column: exact decimal string, never a float. */
    public static function toDb(BrickMoney $money): string
    {
        return (string) BigDecimal::of($money->getAmount())->toScale(self::DB_SCALE, RoundingMode::UNNECESSARY);
    }

    public static function zero(): BrickMoney
    {
        return BrickMoney::zero(self::CURRENCY);
    }

    public static function minorUnits(BrickMoney $money): int
    {
        return $money->getMinorAmount()->toInt();
    }
}
