<?php

declare(strict_types=1);

namespace App\Modules\Administration\Console;

use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Services\DataImportService;
use Illuminate\Console\Command;

/**
 * php artisan import:csv clients clients.csv --dry-run [--as=owner@…] [--date=2026-01-01]  (§9.1)
 * Types: clients, vendors, staff, assets, opening_balances. `--template` prints the header row.
 */
final class ImportCommand extends Command
{
    protected $signature = 'import:csv {type} {file?} {--dry-run} {--as=} {--date=} {--template}';

    protected $description = 'Validate (dry run) or import go-live data from CSV (§9.1).';

    public function handle(DataImportService $imports): int
    {
        $type = (string) $this->argument('type');
        if (! isset(DataImportService::TYPES[$type])) {
            $this->error('Type must be one of: '.implode(', ', array_keys(DataImportService::TYPES)));

            return self::INVALID;
        }
        if ($this->option('template')) {
            $this->line(rtrim(DataImportService::template($type)));

            return self::SUCCESS;
        }
        $file = (string) $this->argument('file');
        if ($file === '' || ! is_readable($file)) {
            $this->error('CSV file not found.');

            return self::INVALID;
        }
        $as = $this->option('as');
        $actor = $as ? User::query()->where('email', $as)->firstOrFail() : User::query()->whereHas('roles', fn ($r) => $r->where('code', 'OWN'))->orderBy('id')->firstOrFail();
        $report = $imports->run($type, (string) file_get_contents($file), $actor, (bool) $this->option('dry-run'), $this->option('date') ?: null);
        $this->info(sprintf('%s: %d rows, %d valid, %d created%s.', $type, $report['rows'], $report['valid'], $report['created'], $report['dry_run'] ? ' (dry run — nothing written)' : ''));
        if ($report['errors'] !== []) {
            $this->table(['Row', 'Field', 'Problem'], array_map(fn (array $e): array => [$e['row'], $e['field'], $e['message']], $report['errors']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
