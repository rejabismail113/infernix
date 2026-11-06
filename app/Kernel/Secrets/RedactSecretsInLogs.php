<?php

declare(strict_types=1);

namespace App\Kernel\Secrets;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Monolog tap (config/logging.php `tap`) that scrubs every log line — message and context — through the
 * SecretRedactor before any handler writes it (M13-02, M13-05, UAT-08).
 *
 * @see SRS M13-05, UAT-08
 */
final class RedactSecretsInLogs
{
    public function __invoke(Logger $logger): void
    {
        $redactor = app(SecretRedactor::class);
        $monolog = $logger->getLogger();
        if (! $monolog instanceof \Monolog\Logger) {
            return;
        }
        $monolog->pushProcessor(function (LogRecord $record) use ($redactor): LogRecord {
            /** @var array<string, mixed> $context */
            $context = $redactor->scrubArray($record->context) ?? [];
            /** @var array<string, mixed> $extra */
            $extra = $redactor->scrubArray($record->extra) ?? [];

            return $record->with(message: $redactor->scrub($record->message), context: $context, extra: $extra);
        });
    }
}
