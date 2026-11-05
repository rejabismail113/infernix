<?php

declare(strict_types=1);

namespace App\Kernel\Money;

use Brick\Money\Money as BrickMoney;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast: NUMERIC(18,4) <-> Brick\Money\Money. Rejects floats at the boundary.
 *
 * @implements CastsAttributes<BrickMoney|null, BrickMoney|string|int|null>
 */
final class MoneyCast implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BrickMoney
    {
        return $value === null ? null : Money::fromDb((string) $value);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException("Money attribute [{$key}] must not be a float.");
        }
        if (! $value instanceof BrickMoney) {
            $value = Money::myr($value);
        }

        return Money::toDb($value);
    }
}
