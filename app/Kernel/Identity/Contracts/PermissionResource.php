<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * A model that is governed by the permission matrix. `resourceName()` is the matrix `resource` key;
 * ownership/assignment answer record-scope questions. Deny-by-default: unknown scope → false.
 *
 * @see SRS G-02, M12-02
 */
interface PermissionResource
{
    public static function resourceName(): string;

    /** Scope `own`: does this record belong to the user? */
    public function isOwnedBy(User $user): bool;

    /** Scope `assigned`: project membership / explicit share / delegated approval item. */
    public function isAssignedTo(User $user): bool;
}
