<?php

declare(strict_types=1);

namespace App\Modules\Administration\Jobs;

use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** Owner-triggered backup / restore test from the Backup Status page. @see SRS M12-06 */
final class RunBackup implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public readonly string $kind, public readonly int $userId) {}

    public function handle(BackupService $backups): void
    {
        $user = User::query()->find($this->userId);
        $this->kind === 'restore_test' ? $backups->restoreTest($user) : $backups->run($user);
    }
}
