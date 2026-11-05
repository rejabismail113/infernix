<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Enums;

/** @see SRS §2 */
enum RoleCode: string
{
    case Owner = 'OWN';
    case Finance = 'FIN';
    case ProjectManager = 'PM';
    case Hr = 'HR';
    case Staff = 'STF';
    case Auditor = 'AUD';
    case Approver = 'APP';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner / Super Admin',
            self::Finance => 'Finance',
            self::ProjectManager => 'Project Manager',
            self::Hr => 'HR / Admin',
            self::Staff => 'Staff / Part-time',
            self::Auditor => 'Accountant / Auditor',
            self::Approver => 'Delegated Approver',
        };
    }

    public function mfaRequired(): bool
    {
        return in_array($this, [self::Owner, self::Finance], true);
    }

    public function readOnly(): bool
    {
        return $this === self::Auditor;
    }
}
