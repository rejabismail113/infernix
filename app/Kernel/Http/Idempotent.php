<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Identity\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replay-safe endpoints. A request carrying `Idempotency-Key` (header or `_idempotency_key` field) is
 * processed once per user+route; a replay with the same body returns the original redirect without
 * re-executing; a replay with a different body is rejected (422). Browser forms send a key generated
 * when the form was opened, so a double-click or refresh cannot post a payment twice.
 *
 * @see SRS §5.1, CLAUDE.md rule 9
 */
final class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) ($request->header('Idempotency-Key') ?? $request->input('_idempotency_key', ''));
        $user = $request->user();
        if ($key === '' || ! $user instanceof User) {
            return $next($request);
        }
        if (strlen($key) > 120) {
            abort(422, 'Idempotency-Key too long.');
        }
        $route = (string) ($request->route()?->getName() ?? $request->path()).':'.implode(',', array_map('strval', $request->route()?->parameters() ?? []));
        $hash = hash('sha256', json_encode($request->except(['_token', '_idempotency_key'])) ?: '');

        $existing = DB::table('idempotency_keys')->where(['user_id' => $user->id, 'key' => $key, 'route' => $route])->first();
        if ($existing !== null) {
            return $this->replay($existing, $hash);
        }
        try {
            $id = DB::table('idempotency_keys')->insertGetId(['user_id' => $user->id, 'key' => $key, 'route' => $route, 'request_hash' => $hash, 'created_at' => now()]);
        } catch (QueryException) {
            // Concurrent duplicate: the other request owns the key.
            $existing = DB::table('idempotency_keys')->where(['user_id' => $user->id, 'key' => $key, 'route' => $route])->first();

            return $existing ? $this->replay($existing, $hash) : abort(409, 'Request already in progress.');
        }

        $response = $next($request);
        if ($response->getStatusCode() < 400 && ! session()->has('errors')) {
            DB::table('idempotency_keys')->where('id', $id)->update([
                'response_status' => $response->getStatusCode(),
                'response_location' => $response->headers->get('Location'),
            ]);
        } else {
            // Failed requests may be retried with the same key.
            DB::table('idempotency_keys')->where('id', $id)->delete();
        }

        return $response;
    }

    private function replay(object $existing, string $hash): Response
    {
        if ($existing->request_hash !== $hash) {
            abort(422, 'This Idempotency-Key was already used for a different request.');
        }
        if ($existing->response_status === null) {
            abort(409, 'The original request is still being processed.');
        }

        return $existing->response_location
            ? redirect()->to((string) $existing->response_location)->with('success', __('Already processed.'))
            : response('', (int) $existing->response_status);
    }
}
