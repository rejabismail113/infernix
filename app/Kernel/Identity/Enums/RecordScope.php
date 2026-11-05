<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Enums;

/** @see SRS G-02, M12-02 */
enum RecordScope: string
{
    case All = 'all';
    case Assigned = 'assigned';
    case Own = 'own';
    case None = 'none';

    public function rank(): int
    {
        return match ($this) {
            self::All => 3, self::Assigned => 2, self::Own => 1, self::None => 0,
        };
    }
}
