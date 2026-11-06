<?php

declare(strict_types=1);

namespace App\Kernel\People\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Read access to M04 staff for other modules (M02 team, M03 memos / appointment letters) — IDs and non-sensitive
 * snapshots only. NRIC/passport and bank details are never part of this contract (M04-06).
 *
 * @see SRS M04-01, M04-06, M02-07, M03-06
 */
interface StaffDirectory
{
    /** @return array{id: int, staff_no: string, name: string, legal_name: string, user_id: int|null, position: string|null, email: string|null, engagement_type: string, classification: string, status: string}|null */
    public function snapshot(int $staffProfileId): ?array;

    /** @return list<array{id: int, label: string, meta: string, user_id: int|null}> active staff the user may pick */
    public function options(User $user): array;

    public function idForUser(User $user): ?int;

    /** @return array{id: int, version: int, rate_type: string, rate_amount: string, effective_from: string}|null rate version in force on a date */
    public function engagementOn(int $staffProfileId, string $date): ?array;
}
