<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every export is audited ('export' event with report code + filters) and every cell passes through
 * SecretRedactor before leaving the system (M13-05, UAT-08). `?format=pdf` on any export link returns the same
 * table as a landscape PDF instead of CSV (owner request 2026-11-07).
 *
 * @see SRS G-08, M11-05, M12-04
 */
final class CsvExport
{
    public function __construct(private readonly SecretRedactor $secrets, private readonly PdfTable $pdf) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function stream(string $reportCode, array $headers, iterable $rows, array $filters = []): StreamedResponse
    {
        if (request()->query('format') === 'pdf') {
            Audit::export($reportCode, ['filters' => $filters, 'format' => 'pdf']);
            $user = request()->user();
            $bytes = $this->pdf->render(ucwords(str_replace(['_', '.'], ' ', $reportCode)), $headers, $rows, $user instanceof User ? $user : null);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $reportCode.'-'.now()->format('Ymd-His').'.pdf', ['Content-Type' => 'application/pdf']);
        }
        Audit::export($reportCode, ['filters' => $filters]);
        $filename = $reportCode.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v): string => $this->secrets->scrub((string) ($v ?? '')), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
