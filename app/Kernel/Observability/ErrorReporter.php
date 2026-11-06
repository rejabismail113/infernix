<?php

declare(strict_types=1);

namespace App\Kernel\Observability;

use App\Kernel\Secrets\SecretRedactor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Error-tracking hook (NFR-10). With SENTRY_DSN set, unhandled exceptions are sent to Sentry's store API
 * (message, type, file:line, request id, release — redacted, no request bodies, no user PII beyond the user id).
 * With OTEL_EXPORTER_OTLP_ENDPOINT set, the JSON log channel is the integration point for an OTel collector
 * (docs/DEPLOYMENT.md). Reporting never throws.
 *
 * @see SRS NFR-10
 */
final class ErrorReporter
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    public function report(Throwable $e): void
    {
        $dsn = (string) config('infernix.observability.sentry_dsn');
        if ($dsn === '' || preg_match('#^(https?)://([^@]+)@([^/]+)/(\d+)$#', $dsn, $m) !== 1) {
            return;
        }
        [, $scheme, $key, $host, $project] = $m;
        try {
            Http::timeout(2)->withHeaders(['X-Sentry-Auth' => "Sentry sentry_version=7, sentry_key={$key}, sentry_client=infernix-one/1.0"])
                ->post("{$scheme}://{$host}/api/{$project}/store/", [
                    'event_id' => str_replace('-', '', (string) Str::uuid()), 'timestamp' => now()->toIso8601String(), 'platform' => 'php', 'level' => 'error',
                    'environment' => app()->environment(), 'release' => config('infernix.release'),
                    'exception' => ['values' => [['type' => $e::class, 'value' => $this->redactor->scrub($e->getMessage()),
                        'stacktrace' => ['frames' => [['filename' => str_replace(base_path(), '', $e->getFile()), 'lineno' => $e->getLine()]]]]]],
                    'tags' => ['request_id' => request()->attributes->get('request_id')], 'user' => ['id' => auth()->id()],
                ]);
        } catch (Throwable) {
            // never let error reporting break the request
        }
    }
}
