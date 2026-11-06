<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

/**
 * e-Invoice submission gateway (MyInvois or a service provider). Implementations MUST be idempotent on
 * $idempotencyKey: submitting the same key twice returns the first result and never creates a second document.
 * Transport/5xx problems throw EInvoiceTransientError (retried with backoff); validation failures return rejected.
 *
 * @see SRS §6.2
 */
interface EInvoiceGateway
{
    public function name(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: 'accepted'|'rejected'|'pending', uuid?: string|null, long_id?: string|null, message?: string|null}
     */
    public function submit(array $payload, string $idempotencyKey): array;
}
