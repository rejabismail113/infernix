<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Audit\Audit;
use App\Kernel\Secrets\SecretRedactor;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every export is audited ('export' event with report code + filters) and every cell passes through
 * SecretRedactor before leaving the system (M13-05, UAT-08).
 *
 * @see SRS G-08, M11-05, M12-04
 */
final class CsvExport
{
    public function __construct(private readonly SecretRedactor $secrets) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @param  array<string, mixed>  $filters
     */
    public function stream(string $reportCode, array $headers, iterable $rows, array $filters = []): StreamedResponse
    {
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
