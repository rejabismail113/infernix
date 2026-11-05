<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Events;

/** Cumulative invoiced / settled totals of a project changed (decimal strings) — M02 updates status_payment. @see docs/ARCHITECTURE.md §4 */
final class ProjectBillingChanged
{
    public function __construct(public readonly int $projectId, public readonly string $invoiced, public readonly string $settled) {}
}
