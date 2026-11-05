<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Lint rule (CLAUDE.md rule 1): money columns/attributes must never be floats.
 * Fails if any migration uses float/double/real column types, or any model casts to float/double.
 *
 * @see SRS §6.3
 */
it('never declares float or double columns in migrations', function (): void {
    $offenders = [];
    $finder = (new Finder)->files()->in([base_path('database/migrations'), base_path('app')])->name('*.php')->path('/[Mm]igrations/');
    foreach ($finder as $file) {
        if (preg_match_all('/->(float|double|unsignedFloat|unsignedDouble|real)\s*\(/', $file->getContents(), $m)) {
            $offenders[] = $file->getRelativePathname().': '.implode(', ', $m[0]);
        }
    }
    expect($offenders)->toBe([]);
})->group('M01-06');

it('never casts model attributes to float or double', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        if (preg_match('/=>\s*[\'"](float|double|real)[\'"]/', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe([]);
})->group('M01-06');

it('uses brick/money for money math (no bcmath-free float arithmetic on amounts)', function (): void {
    $offenders = [];
    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        if (preg_match('/\(float\)\s*\$\w*(amount|total|price|debit|credit|balance)/i', $file->getContents())) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe([]);
})->group('M01-06');
