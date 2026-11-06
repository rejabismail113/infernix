<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Events;

/**
 * A staff member joined, moved (position / engagement change) or left (status → inactive). M13 opens the
 * joiner-mover-leaver access checklist and, for leavers, revokes credential entitlements (M13-04, WF-07).
 *
 * @see SRS M13-04, WF-07, docs/ARCHITECTURE.md §4
 */
final class StaffLifecycleChanged
{
    /** @param  'joiner'|'mover'|'leaver'  $kind */
    public function __construct(
        public readonly int $staffProfileId,
        public readonly ?int $userId,
        public readonly string $kind,
        public readonly string $detail,
        public readonly int $actorId,
    ) {}
}
