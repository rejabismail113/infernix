<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services\Scanners;

use App\Kernel\Documents\Contracts\DocumentScanner;
use App\Kernel\Documents\ScanResult;
use App\Kernel\Documents\ScanStatus;
use Symfony\Component\Process\Process;

/**
 * ClamAV via `clamdscan --fdpass`. Exit 0 = clean, 1 = infected, anything else = error (fails closed).
 *
 * @see SRS G-07
 */
final class ClamAvScanner implements DocumentScanner
{
    public function __construct(private readonly string $binary) {}

    public function scan(string $absolutePath): ScanResult
    {
        if (! is_executable($this->binary)) {
            return new ScanResult(ScanStatus::Error, 'clamav', 'clamdscan not available');
        }
        $process = new Process([$this->binary, '--no-summary', '--fdpass', $absolutePath]);
        $process->setTimeout(60);
        $process->run();

        return match ($process->getExitCode()) {
            0 => new ScanResult(ScanStatus::Clean, 'clamav'),
            1 => new ScanResult(ScanStatus::Infected, 'clamav', trim($process->getOutput())),
            default => new ScanResult(ScanStatus::Error, 'clamav', trim($process->getErrorOutput())),
        };
    }
}
