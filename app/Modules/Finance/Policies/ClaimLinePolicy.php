<?php

declare(strict_types=1);

namespace App\Modules\Finance\Policies;

use App\Kernel\Identity\Models\User;
use App\Modules\Finance\Models\ClaimLine;
use Illuminate\Support\Facades\Gate;

/** A claim line follows its claim; receipts may be attached while the claim is editable. @see SRS M01-03 */
final class ClaimLinePolicy
{
    public function view(User $user, ClaimLine $line): bool
    {
        return Gate::forUser($user)->allows('view', $line->claim);
    }

    public function update(User $user, ClaimLine $line): bool
    {
        return Gate::forUser($user)->allows('update', $line->claim);
    }
}
