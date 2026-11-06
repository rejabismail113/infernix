<?php

declare(strict_types=1);

namespace App\Modules\Administration\Console;

use Illuminate\Console\Command;

/** Placeholder replaced by the data-migration importer (§9.1). */
final class ImportCommand extends Command
{
    protected $signature = 'import:csv';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
