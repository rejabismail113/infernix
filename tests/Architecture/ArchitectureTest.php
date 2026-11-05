<?php

declare(strict_types=1);
use Symfony\Component\Finder\Finder;

/** Bounded-context rules from docs/ARCHITECTURE.md. */
arch('no debugging helpers leak into the codebase')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

it('declares strict_types in every PHP file under app/, database/, routes/ and tests/', function (): void {
    $missing = [];
    $finder = (new Finder)->files()->in([base_path('app'), base_path('database'), base_path('routes'), base_path('tests')])->name('*.php');
    foreach ($finder as $file) {
        if (! str_contains($file->getContents(), 'declare(strict_types=1);')) {
            $missing[] = $file->getRelativePathname();
        }
    }
    expect($missing)->toBe([]);
})->group('NFR-11');

arch('the kernel never depends on business modules')
    ->expect('App\Kernel')
    ->not->toUse('App\Modules');

arch('modules never reach into another module\'s Models or Services directly (events/IDs only)')
    ->expect('App\Modules\Assets')->not->toUse(['App\Modules\Finance\Services', 'App\Modules\Finance\Models'])
    ->and('App\Modules\Csr')->not->toUse(['App\Modules\Finance\Services'])
    ->and('App\Modules\Activities')->not->toUse(['App\Modules\Finance\Services'])
    ->and('App\Modules\Certifications')->not->toUse(['App\Modules\Finance\Services'])
    ->and('App\Modules\Procurement')->not->toUse(['App\Modules\Finance\Services']);

arch('journals are only created through the PostingService (M01-06)')
    ->expect('App\Modules')
    ->not->toUse('App\Modules\Finance\Models\Journal::create');
