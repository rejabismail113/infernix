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

it('writes journals and journal lines only inside PostingService (M01-06)', function (): void {
    $pattern = '/table\(\s*[\'"]journal(s|_lines)[\'"]\s*\)[^;]*->(insert|update|delete|upsert)'
        .'|Journal(Line)?::(query\(\)->)?(create|forceCreate|insert|upsert|updateOrCreate|firstOrCreate)\b'
        .'|new\s+Journal(Line)?\s*\('
        .'|(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+journal(s|_lines)\b/i';
    $offenders = [];
    foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
        if ($file->getFilename() === 'PostingService.php') {
            continue;
        }
        if (preg_match($pattern, $file->getContents()) === 1) {
            $offenders[] = $file->getRelativePathname();
        }
    }
    expect($offenders)->toBe([]);
})->group('M01-06', 'UAT-05');

arch('Finance never depends on Commercial (payment vouchers go through the kernel IssuedDocumentRenderer)')
    ->expect('App\Modules\Finance')->not->toUse('App\Modules\Commercial')
    ->group('M03-06');

arch('Commercial reaches Finance only through LedgerGateway and Projects only through kernel CRM contracts')
    ->expect('App\Modules\Commercial')->not->toUse(['App\Modules\Finance\Models', 'App\Modules\Finance\Posting', 'App\Modules\Finance\Services\PostingService', 'App\Modules\Projects'])
    ->group('M03-02', 'M03-03');

arch('Projects and Reporting never write through other modules\' models')
    ->expect('App\Modules\Projects')->not->toUse(['App\Modules\Commercial\Models', 'App\Modules\Commercial\Services', 'App\Modules\Finance'])
    ->and('App\Modules\Reporting')->not->toUse(['App\Modules\Commercial\Models', 'App\Modules\Commercial\Services', 'App\Modules\Finance\Models', 'App\Modules\Finance\Services'])
    ->group('M11-05');

arch('HR reaches Finance only through LedgerGateway and Projects only through kernel contracts')
    ->expect('App\Modules\HumanResources')->not->toUse(['App\Modules\Finance\Models', 'App\Modules\Finance\Posting', 'App\Modules\Finance\Services\PostingService', 'App\Modules\Projects', 'App\Modules\Commercial\Models', 'App\Modules\Commercial\Services'])
    ->group('M04-03', 'WF-05');

arch('Projects never import HR or Commercial models/services (staff and certificates via kernel contracts)')
    ->expect('App\Modules\Projects')->not->toUse(['App\Modules\HumanResources', 'App\Modules\Commercial\Models', 'App\Modules\Commercial\Services'])
    ->and('App\Modules\Commercial')->not->toUse(['App\Modules\HumanResources'])
    ->group('M02-07', 'M03-06');
