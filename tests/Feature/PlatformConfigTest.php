<?php

declare(strict_types=1);

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
