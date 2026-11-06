<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Identity\Models\User;
use Closure;

/**
 * One exportable report. `runner(array $filters): array` returns
 * ['title', 'columns' => [[key,label,numeric?]], 'rows', 'totals', 'notes'] with money as decimal strings.
 * Access is the permission matrix `resource` + action (export to download, view to see).
 *
 * @see SRS M11-05, §8.1
 */
final class ReportDefinition
{
    /** @param  list<string>  $filters  which of from|to|as_of|basis|account_id the report uses */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $module,
        public readonly string $resource,
        public readonly Closure $runner,
        public readonly array $filters = ['from', 'to'],
    ) {}

    /**
     * The runner also receives the requesting user so reports can apply row-level record scope (M11-05).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(array $filters, ?User $user = null): array
    {
        return ($this->runner)($filters, $user);
    }
}
