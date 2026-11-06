<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

use Illuminate\Support\Facades\Cache;

/**
 * Stub gateway (the only adapter until the owner and accountant confirm MyInvois obligations). Deterministic:
 * the same idempotency key always yields the same UUID; a zero / missing TIN is rejected like the real validator.
 * `infernix.einvoice.stub_fail_times` simulates transient outages for retry tests.
 *
 * @see SRS §6.2
 */
final class StubEInvoiceGateway implements EInvoiceGateway
{
    public function name(): string
    {
        return 'stub';
    }

    public function submit(array $payload, string $idempotencyKey): array
    {
        $failKey = 'einvoice-stub-fail:'.$idempotencyKey;
        $failures = (int) Cache::get($failKey, 0);
        if ($failures < (int) config('infernix.einvoice.stub_fail_times', 0)) {
            Cache::put($failKey, $failures + 1, 3600);
            throw new EInvoiceTransientError('Simulated gateway timeout');
        }
        if (empty($payload['buyer']['tin'] ?? null) || ($payload['totals']['payable'] ?? '0') === '0.00') {
            return ['status' => 'rejected', 'message' => 'Buyer TIN is required and the payable amount must be positive.'];
        }
        $uuid = 'STUB-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 26));

        return ['status' => 'accepted', 'uuid' => $uuid, 'long_id' => 'LONG-'.substr(hash('sha256', $uuid), 0, 40), 'message' => null];
    }
}
