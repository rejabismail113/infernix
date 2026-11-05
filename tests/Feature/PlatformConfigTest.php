<?php

declare(strict_types=1);

use App\Kernel\Audit\Audit;
use App\Kernel\KernelServiceProvider;

/** @see SRS G-12, NFR-12 */
it('runs in Asia/Kuala_Lumpur with MYR as base currency', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Kuala_Lumpur')
        ->and(date_default_timezone_get())->toBe('Asia/Kuala_Lumpur')
        ->and(config('infernix.currency'))->toBe('MYR')
        ->and(config('infernix.roles'))->toBe(['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP']);
})->group('G-12', 'NFR-12');

it('registers the kernel and all thirteen module service providers', function (): void {
    $providers = require base_path('bootstrap/providers.php');

    expect($providers)->toContain(KernelServiceProvider::class);
    foreach (['Finance', 'Projects', 'Commercial', 'HumanResources', 'Assets', 'DocumentCentre', 'Activities', 'Csr', 'Certifications', 'Procurement', 'Reporting', 'Administration', 'Credentials'] as $module) {
        $class = "App\\Modules\\{$module}\\{$module}ServiceProvider";
        expect($providers)->toContain($class)
            ->and($this->app->getProvider($class))->not->toBeNull();
    }
})->group('NFR-11');

it('uses PostgreSQL as the authoritative database in tests', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::connection()->getDriverName())->toBe('pgsql');
})->group('NFR-11');

it('never serves documents from a public disk', function (): void {
    foreach (['documents', 'documents_local', 'documents_test'] as $disk) {
        expect(config("filesystems.disks.{$disk}.visibility"))->toBe('private');
        expect(config("filesystems.disks.{$disk}.serve", false))->toBeFalse();
    }
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
})->group('M06-02', 'UAT-10');

it('stores timestamps as the correct instant (DB session uses the app timezone)', function (): void {
    $row = DB::selectOne("select current_setting('TimeZone') as tz, extract(epoch from (?::timestamptz)) as epoch", [now()->format('Y-m-d H:i:s')]);
    expect($row->tz)->toBe('Asia/Kuala_Lumpur')
        ->and(abs((int) $row->epoch - now()->getTimestamp()))->toBeLessThan(2);

    $event = Audit::log('tz_check', 'Thing', 1);
    $dbEpoch = (int) DB::selectOne('select extract(epoch from occurred_at) as e from audit_events where id = ?', [$event->id])->e;
    expect(abs($dbEpoch - now()->getTimestamp()))->toBeLessThan(5)
        ->and($event->fresh()->occurred_at->isFuture())->toBeFalse();
})->group('G-12', 'NFR-12');
