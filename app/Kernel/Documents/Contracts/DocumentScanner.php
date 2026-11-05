<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

use App\Kernel\Documents\ScanResult;

/**
 * Malware scan hook. `NullScanner` in dev (status skipped); `ClamAvScanner` when configured.
 * Uploads are rejected unless the result allows storage (fail closed).
 *
 * @see SRS G-07
 */
interface DocumentScanner
{
    public function scan(string $absolutePath): ScanResult;
}
