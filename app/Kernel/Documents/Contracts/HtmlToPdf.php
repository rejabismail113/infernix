<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

/** HTML → PDF bytes (M03 implements it with Browsershot / DomPDF). @see SRS M08 (CSR annual report), M03-01 */
interface HtmlToPdf
{
    public function render(string $html, bool $landscape = false): string;
}
