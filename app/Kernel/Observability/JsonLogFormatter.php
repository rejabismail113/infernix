<?php

declare(strict_types=1);

namespace App\Kernel\Observability;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Monolog tap for structured JSON logs (NFR-10): one JSON object per line with level, message, context
 * (request_id, user_id, route…), app and environment — ready for Loki / CloudWatch / an OTel collector.
 * The SecretRedactor tap runs first on every channel (UAT-08).
 *
 * @see SRS NFR-10
 */
final class JsonLogFormatter
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }
        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new JsonFormatter(JsonFormatter::BATCH_MODE_NEWLINES, true, true));
            }
        }
        $monolog->pushProcessor(fn (LogRecord $r): LogRecord => $r->with(extra: $r->extra + ['app' => config('app.name'), 'env' => app()->environment()]));
    }
}
