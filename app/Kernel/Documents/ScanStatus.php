<?php

declare(strict_types=1);

namespace App\Kernel\Documents;

/** @see SRS G-07 */
enum ScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
    case Error = 'error';

    /** Only clean or skipped (no scanner configured) files may be stored. */
    public function allowsStorage(): bool
    {
        return in_array($this, [self::Clean, self::Skipped], true);
    }
}
