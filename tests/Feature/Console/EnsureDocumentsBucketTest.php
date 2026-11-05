<?php

declare(strict_types=1);

/** @see SRS M06-02, G-07 */
it('storage:ensure-bucket is a no-op on a non-S3 documents disk', function (): void {
    config(['infernix.documents.disk' => 'documents_local']);
    $this->artisan('storage:ensure-bucket', ['--wait' => 0])
        ->expectsOutputToContain('not S3-backed')
        ->assertSuccessful();
})->group('M06-02', 'G-07');

it('storage:ensure-bucket fails cleanly when the object store is unreachable', function (): void {
    config([
        'infernix.documents.disk' => 'documents',
        'filesystems.disks.documents.endpoint' => 'http://127.0.0.1:1',
        'filesystems.disks.documents.key' => 'k', 'filesystems.disks.documents.secret' => 's',
    ]);
    $this->artisan('storage:ensure-bucket', ['--wait' => 0])
        ->expectsOutputToContain('Object store not reachable')
        ->assertFailed();
})->group('M06-02', 'G-07');
