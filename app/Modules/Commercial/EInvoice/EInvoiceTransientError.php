<?php

declare(strict_types=1);

namespace App\Modules\Commercial\EInvoice;

use RuntimeException;

/** Retryable gateway failure (timeout, 429, 5xx). @see SRS §6.2 */
final class EInvoiceTransientError extends RuntimeException {}
