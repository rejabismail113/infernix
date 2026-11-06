<?php

declare(strict_types=1);

namespace App\Kernel\Crm\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Read access to M07 activities for other modules (M08 CSR programmes, M10 purchase requests, M01 forms) —
 * IDs and snapshots only.
 *
 * @see SRS M07-02, M08-02
 */
interface ActivityDirectory
{
    /** @return array{id: int, activity_no: string, title: string, type: string, start_date: string, end_date: string, project_id: int|null, client_id: int|null, budget: string, status: string}|null */
    public function snapshot(int $activityId): ?array;

    /** @return list<array{id: int, label: string}> activities the user may view */
    public function options(User $user): array;

    /** Create the activity that carries a CSR programme's finance linkage (type csr). Returns its id. */
    public function createForCsr(string $title, string $date, ?string $location, string $budget, User $actor): int;

    /**
     * Budget vs actual for an activity: actual = booked finance lines tagged to it (claims, expenses, bills).
     *
     * @return array{budget: string, actual: string, variance: string, by_kind: array<string, string>}
     */
    public function budgetVsActual(int $activityId): array;
}
