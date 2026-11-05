<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services\Scanners;

use App\Kernel\Documents\Contracts\DocumentScanner;
use App\Kernel\Documents\ScanResult;

/** Dev/test scanner: records `skipped` (no malware engine configured). @see SRS G-07 */
final class NullScanner implements DocumentScanner
{
    public function scan(string $absolutePath): ScanResult
    {
        return ScanResult::skipped('null');
    }
}
