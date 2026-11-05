<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

use App\Kernel\Documents\IssuedDocumentRequest;

/**
 * Renders a numbered business document through the M03 template engine, records it in the document register
 * and stores the PDF in the Document Centre as a frozen `issued_document`. Lets M01 produce payment vouchers
 * without depending on M03 (docs/ARCHITECTURE.md §3).
 *
 * @see SRS M03-06, M03-07, M03-08
 */
interface IssuedDocumentRenderer
{
    /** @return int the Document Centre document id of the frozen PDF */
    public function issue(IssuedDocumentRequest $request): int;
}
