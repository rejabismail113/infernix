<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @see SRS §6.2
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $idempotency_key
 * @property string $gateway
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property string|null $last_error
 * @property string|null $external_uuid
 * @property string|null $long_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $accepted_at
 */
final class EInvoiceOutbox extends Model
{
    protected $table = 'einvoice_outbox';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'submitted_at' => 'datetime', 'accepted_at' => 'datetime', 'attempts' => 'integer'];
    }
}
