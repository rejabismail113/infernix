<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

/**
 * Builds the report payload shape the Export Centre / report viewer expect:
 * ['title', 'columns' => [['key','label','numeric'?]], 'rows', 'totals', 'notes'].
 *
 * @see SRS M11-05
 */
final class ReportTable
{
    /**
     * @param  array<string, string>  $columns  key => label; prefix the label with "#" for a numeric (money) column
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $totals
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    public static function make(string $title, array $columns, array $rows, array $totals = [], array $notes = []): array
    {
        $cols = [];
        foreach ($columns as $key => $label) {
            $cols[] = str_starts_with($label, '#') ? ['key' => $key, 'label' => substr($label, 1), 'numeric' => true] : ['key' => $key, 'label' => $label];
        }

        return ['title' => $title, 'columns' => $cols, 'rows' => $rows, 'totals' => $totals, 'notes' => $notes];
    }
}
