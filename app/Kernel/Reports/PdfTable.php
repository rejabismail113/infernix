<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Documents\Contracts\HtmlToPdf;
use App\Kernel\Identity\Models\User;
use App\Kernel\Secrets\SecretRedactor;

/**
 * Renders any report table as a landscape A4 PDF (owner request: every export also as PDF). Same safety as the
 * CSV / XLSX writers: every cell passes through the SecretRedactor (UAT-08) and is HTML-escaped by Blade.
 *
 * @see SRS M11-05, G-08, UAT-08
 */
final class PdfTable
{
    public function __construct(private readonly SecretRedactor $secrets, private readonly HtmlToPdf $pdf) {}

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @param  list<int>  $numericColumns  column indexes to right-align
     * @return string PDF bytes
     */
    public function render(string $title, array $headers, iterable $rows, ?User $user = null, array $numericColumns = []): string
    {
        $clean = [];
        foreach ($rows as $row) {
            $clean[] = array_map(fn ($v): string => $this->secrets->scrub(is_bool($v) ? ($v ? 'Yes' : 'No') : (string) ($v ?? '')), array_values($row));
        }
        $html = view('reports.table-pdf', [
            'title' => $title,
            'headers' => array_map(fn (string $h): string => $this->secrets->scrub($h), $headers),
            'rows' => $clean,
            'numeric' => array_flip($numericColumns),
            'generatedAt' => now()->timezone('Asia/Kuala_Lumpur')->format('j M Y, g:i A'),
            'generatedBy' => $user?->name,
        ])->render();

        return $this->pdf->render($html, true);
    }

    /**
     * Write the PDF to a temp file (for the Export Centre, which stores files in the Document Centre).
     *
     * @param  list<string>  $headers
     * @param  iterable<int, array<int, scalar|null>>  $rows
     * @param  list<int>  $numericColumns
     */
    public function write(string $title, array $headers, iterable $rows, ?User $user = null, array $numericColumns = []): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'exp');
        file_put_contents($path, $this->render($title, $headers, $rows, $user, $numericColumns));

        return $path;
    }
}
