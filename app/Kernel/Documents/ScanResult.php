<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

/** @see SRS G-07 */
final class ScanResult
{
    public function __construct(public readonly ScanStatus $status, public readonly string $scanner, public readonly ?string $detail = null) {}

    public static function skipped(string $scanner = 'none'): self
    {
        return new self(ScanStatus::Skipped, $scanner);
    }
}
