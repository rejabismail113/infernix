<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

use App\Modules\Commercial\Events\DocumentIssued;

/** Issued invoice → e-Invoice outbox (only with features.myinvois). @see SRS §6.2 */
final class EnqueueIssuedInvoice
{
    public function __construct(private readonly EInvoiceService $service) {}

    public function handle(DocumentIssued $e): void
    {
        if ($e->type === 'invoice') {
            $this->service->enqueue($e->documentId);
        }
    }
}
