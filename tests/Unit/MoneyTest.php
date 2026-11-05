<?php

declare(strict_types=1);

use App\Kernel\Money\Money;
use App\Kernel\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;

/** @see SRS §6.3 — amounts are exact decimals, never binary floats. */
it('creates exact MYR amounts from strings and serialises to NUMERIC(18,4)', function (): void {
    $m = Money::myr('10000.00')->minus(Money::myr('3000'));

    expect($m->getAmount()->__toString())->toBe('7000.00')
        ->and(Money::toDb($m))->toBe('7000.0000')
        ->and(Money::minorUnits($m))->toBe(700000)
        ->and(Money::fromDb('0.1000')->plus(Money::fromDb('0.2000'))->isEqualTo(Money::myr('0.30')))->toBeTrue();
})->group('UAT-04', 'M01-06');

it('refuses floats at the Eloquent boundary', function (): void {
    $cast = new MoneyCast;
    $model = new class extends Model {};

    expect($cast->set($model, 'amount', '12.5', []))->toBe('12.5000')
        ->and($cast->set($model, 'amount', 7, []))->toBe('7.0000')
        ->and($cast->get($model, 'amount', '7000.0000', [])?->getAmount()->__toString())->toBe('7000.00');

    expect(fn () => $cast->set($model, 'amount', 12.5, []))->toThrow(InvalidArgumentException::class);
})->group('M01-06');
