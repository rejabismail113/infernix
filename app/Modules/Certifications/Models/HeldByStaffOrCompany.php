<?php

declare(strict_types=1);

namespace App\Modules\Certifications\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\People\Contracts\StaffDirectory;

/**
 * Record scope shared by certifications and memberships: "own" = held by me (or I recorded it).
 *
 * @see SRS M09-01, NFR-02
 */
trait HeldByStaffOrCompany
{
    public function isOwnedBy(User $user): bool
    {
        if ($this->created_by === $user->id) {
            return true;
        }

        return $this->staff_profile_id !== null && $this->staff_profile_id === app(StaffDirectory::class)->idForUser($user);
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->isOwnedBy($user);
    }

    public function holderLabel(): string
    {
        return $this->holder_type === 'company' ? 'Company' : (app(StaffDirectory::class)->snapshot((int) $this->staff_profile_id)['name'] ?? 'Staff');
    }
}
