<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * HTML → PDF. Browsershot (headless Chromium) by default; DomPDF when PDF_DRIVER=dompdf or when Chromium is
 * unavailable (logged), so issuing never depends on a browser being installed.
 *
 * @see SRS M03-01, M03-08 (Stack: PDF)
 */
final class PdfRenderer
{
    public function render(string $html, bool $landscape = false): string
    {
        if (config('infernix.pdf.driver') === 'browsershot') {
            try {
                return Browsershot::html($html)->format('A4')->landscape($landscape)->showBackground()->margins(12, 12, 14, 12)
                    ->noSandbox()->timeout(60)->pdf();
            } catch (Throwable $e) {
                Log::warning('Browsershot unavailable; falling back to DomPDF', ['error' => $e->getMessage()]);
            }
        }

        return Pdf::loadHTML($html)->setPaper('a4', $landscape ? 'landscape' : 'portrait')->output();
    }
}
