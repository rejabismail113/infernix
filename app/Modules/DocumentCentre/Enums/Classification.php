<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Enums;

/** @see SRS M06 field list (classification), NFR-07 */
enum Classification: string
{
    case PublicInternal = 'public_internal';
    case Confidential = 'confidential';
    case Restricted = 'restricted';
    case Medical = 'medical';

    public function label(): string
    {
        return match ($this) {
            self::PublicInternal => 'Public (internal)',
            self::Confidential => 'Confidential',
            self::Restricted => 'Restricted',
            self::Medical => 'Medical',
        };
    }
}
