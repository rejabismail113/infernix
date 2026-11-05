<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Enums;

/** @see SRS G-02, M12-02 */
enum PermissionAction: string
{
    case View = 'view';
    case Create = 'create';
    case Edit = 'edit';
    case Approve = 'approve';
    case Pay = 'pay';
    case Export = 'export';
    case Delete = 'delete';
    case Reveal = 'reveal';

    /** Actions an AUD (read-only) role may ever hold. */
    public function isReadOnly(): bool
    {
        return in_array($this, [self::View, self::Export], true);
    }
}
