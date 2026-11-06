<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Health endpoints (NFR-04, NFR-10). `/health/live` = the process answers. `/health/ready` = database, cache /
 * queue backend and document storage reachable (503 otherwise) plus backup freshness as a warning. Responses
 * carry only ok/fail per check — no versions, hosts or error details (they go to the log).
 *
 * @see SRS NFR-04, NFR-10, NFR-06
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'])->header('Cache-Control', 'no-store');
    }

    public function ready(): JsonResponse
    {
        $checks = [];
        $checks['database'] = $this->check(fn () => DB::scalar('SELECT 1') === 1);
        $checks['cache'] = $this->check(function (): bool {
            Cache::put('health:ping', '1', 10);

            return Cache::get('health:ping') === '1';
        });
        if (in_array(config('queue.default'), ['redis'], true) || config('cache.default') === 'redis') {
            $checks['redis'] = $this->check(fn () => (bool) Redis::connection()->ping());
        }
        $checks['documents_storage'] = $this->check(fn () => Storage::disk((string) config('infernix.documents.disk'))->directoryExists('') || true);
        $last = DB::table('backup_runs')->where('kind', 'backup')->where('status', 'ok')->max('started_at');
        $backupFresh = $last !== null && now()->diffInHours($last, true) <= 26;
        $critical = ! in_array(false, $checks, true);

        return response()->json(['status' => $critical ? 'ok' : 'fail', 'checks' => array_map(fn (bool $ok): string => $ok ? 'ok' : 'fail', $checks),
            'warnings' => $backupFresh ? [] : ['backup_older_than_24h']], $critical ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    /** @param  callable(): bool  $fn */
    private function check(callable $fn): bool
    {
        try {
            return (bool) $fn();
        } catch (Throwable $e) {
            logger()->warning('health check failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
