<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Kernel\Reports\PdfTable;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\TabularFileWriter;
use App\Modules\Reporting\Models\Export;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Export Centre: pick report → CSV / XLSX / PDF → authorised against the report's own permission → file stored in the
 * Document Centre (private, linked to the export, owned by the requester) → audited → signed download.
 *
 * @see SRS M11-05, §8.1, UAT-08, NFR-02
 */
final class ExportService
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly PermissionService $permissions,
        private readonly TabularFileWriter $writer,
        private readonly DocumentStore $documents,
        private readonly PdfTable $pdf,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public function export(User $user, string $reportKey, string $format, array $filters): Export
    {
        $report = $this->catalog->find($reportKey) ?? throw new InvalidArgumentException('Unknown report.');
        if (! in_array($format, ['csv', 'xlsx', 'pdf'], true)) {
            throw new InvalidArgumentException('Unknown format.');
        }
        if (! $this->permissions->can($user, PermissionAction::Export, $report->resource) || ! $this->permissions->can($user, PermissionAction::Create, 'exports')) {
            throw new AuthorizationException('You may not export this report.');
        }
        $filters = array_intersect_key($filters, array_flip($report->filters));
        $data = $report->run($filters, $user);
        $keys = array_map(fn (array $c): string => $c['key'], $data['columns']);
        $rows = array_map(fn (array $r): array => array_map(fn (string $k) => is_scalar($r[$k] ?? null) ? (is_int($r[$k]) ? $r[$k] : (string) $r[$k]) : null, $keys), $data['rows']);
        foreach ($data['totals'] as $k => $v) {
            $rows[] = array_merge(['TOTAL '.$k], array_fill(0, max(0, count($keys) - 2), ''), [(string) $v]);
        }
        $headers = array_map(fn (array $c): string => $c['label'], $data['columns']);
        $numeric = array_keys(array_filter($data['columns'], fn (array $c): bool => ! empty($c['numeric'])));
        $path = $format === 'pdf'
            ? $this->pdf->write($data['title'] ?? $report->name, $headers, $rows, $user, array_map(intval(...), $numeric))
            : $this->writer->write($format, $headers, $rows, $report->name);

        $export = Export::query()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'report_key' => $reportKey, 'format' => $format, 'filters' => $filters, 'row_count' => count($data['rows'])]);
        $name = str_replace('.', '_', $reportKey).'-'.now()->format('Ymd-His').'.'.$format;
        $mime = match ($format) {
            'csv' => 'text/csv', 'pdf' => 'application/pdf',
            default => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
        try {
            $stored = $this->documents->attach(new UploadedFile($path, $name, $mime, null, true), $user, [
                'title' => $report->name.' export ('.strtoupper($format).')', 'classification' => 'confidential', 'retention_rule' => 'short_1y',
            ], new LinkTarget('export', $export->id, 'export_file'));
        } finally {
            @unlink($path);
        }
        $export->forceFill(['document_id' => $stored->documentId])->save();
        Audit::export($reportKey, ['format' => $format, 'filters' => $filters, 'rows' => count($data['rows']), 'export_id' => $export->id], $user);

        return $export;
    }
}
