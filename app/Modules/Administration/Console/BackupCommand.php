<?php

declare(strict_types=1);

namespace App\Modules\Administration\Console;

use App\Modules\Administration\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** php artisan backup:run | backup:restore-test | backup:prune  (M12-06, NFR-06) */
final class BackupCommand extends Command
{
    protected $signature = 'backup:run {action=backup : backup | restore-test | prune}';

    protected $description = 'Encrypted database backup, restore test into a scratch DB, or retention prune (NFR-06).';

    public function handle(BackupService $backups): int
    {
        $action = (string) $this->argument('action');
        if ($action === 'prune') {
            $this->info($backups->prune().' expired backups deleted.');

            return self::SUCCESS;
        }
        $id = $action === 'restore-test' ? $backups->restoreTest() : $backups->run();
        $run = DB::table('backup_runs')->find($id);
        $this->line(json_encode(['run' => $id, 'kind' => $run->kind ?? null, 'status' => $run->status ?? null, 'object_key' => $run->object_key ?? null, 'details' => json_decode((string) ($run->details ?? 'null'), true), 'error' => $run->error ?? null], JSON_PRETTY_PRINT) ?: '');

        return ($run->status ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
