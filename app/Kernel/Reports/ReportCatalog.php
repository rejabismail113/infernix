<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;

/**
 * Registry of reports modules expose to the Export Centre (M11). Modules register in their provider; the
 * catalog filters by the same permission matrix as the report pages (M11-05).
 *
 * @see SRS M11-05, §8.1
 */
final class ReportCatalog
{
    /** @var array<string, ReportDefinition> */
    private array $reports = [];

    public function __construct(private readonly PermissionService $permissions) {}

    public function register(ReportDefinition $report): void
    {
        $this->reports[$report->key] = $report;
    }

    public function find(string $key): ?ReportDefinition
    {
        return $this->reports[$key] ?? null;
    }

    /** @return list<ReportDefinition> reports the user may export */
    public function exportableBy(User $user): array
    {
        return array_values(array_filter($this->reports, fn (ReportDefinition $r): bool => $this->permissions->can($user, PermissionAction::Export, $r->resource)));
    }

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        return array_values($this->reports);
    }
}
