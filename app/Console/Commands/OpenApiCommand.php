<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Kernel\Http\Idempotent;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Generates docs/openapi.json (OpenAPI 3.1) from the live route table plus the generated route × role matrix in
 * docs/PERMISSION_MATRIX.md, so the API reference, the permission doc and the code cannot drift
 * (tests/Feature/Platform/OpenApiTest.php fails when the committed file is stale).
 *
 *   php artisan api:openapi            write docs/openapi.json
 *   php artisan api:openapi --check    exit 1 if the committed file differs
 *
 * @see SRS NFR-11, §5.1
 */
final class OpenApiCommand extends Command
{
    protected $signature = 'api:openapi {--check : Fail if docs/openapi.json is out of date}';

    protected $description = 'Generate docs/openapi.json from the route table and the permission matrix';

    private const ROLES = ['OWN', 'FIN', 'PM', 'HR', 'STF', 'AUD', 'APP'];

    private const SKIP = ['horizon.index', 'sanctum.csrf-cookie', 'storage.local', 'ignition.healthCheck', 'ignition.executeSolution', 'ignition.updateConfig'];

    public function handle(Router $router): int
    {
        $json = json_encode(self::build($router), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        $path = base_path('docs/openapi.json');
        if ($this->option('check')) {
            $same = is_file($path) && file_get_contents($path) === $json;
            $same ? $this->info('docs/openapi.json is current.') : $this->error('docs/openapi.json is stale — run php artisan api:openapi.');

            return $same ? self::SUCCESS : self::FAILURE;
        }
        file_put_contents($path, $json);
        $this->info('Wrote docs/openapi.json');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    public static function build(Router $router): array
    {
        $matrix = self::matrix();
        $paths = [];
        $routes = array_filter($router->getRoutes()->getRoutes(), fn (Route $r): bool => ! in_array($r->getName(), self::SKIP, true) && ! str_starts_with($r->uri(), '_'));
        usort($routes, fn (Route $a, Route $b): int => [$a->uri(), self::method($a)] <=> [$b->uri(), self::method($b)]);
        foreach ($routes as $route) {
            $name = $route->getName() ?? (implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri());
            $uri = '/'.ltrim((string) preg_replace('/\{(\w+)\?\}/', '{$1}', $route->uri()), '/');
            $middleware = array_map(fn ($m): string => is_string($m) ? $m : '', $route->gatherMiddleware());
            $public = ! in_array('auth', $middleware, true) && ! in_array('app', $middleware, true);
            $signed = (bool) array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'signed') || str_contains($m, 'ValidateSignature'));
            $idempotent = (bool) array_filter($middleware, fn (string $m): bool => $m === 'idempotent' || str_starts_with($m, Idempotent::class));
            $recent = (bool) array_filter($middleware, fn (string $m): bool => $m === 'recent-auth' || str_starts_with($m, RequireRecentAuth::class));
            $throttle = array_values(array_filter($middleware, fn (string $m): bool => str_starts_with($m, 'throttle')));
            $codes = $matrix[$name] ?? null;
            $allowed = $codes === null ? [] : array_values(array_filter(self::ROLES, fn (string $r): bool => in_array($codes[$r], ['A', 'V', 'R'], true)));

            $op = [
                'operationId' => $name,
                'tags' => [ucfirst(explode('.', (string) ($route->getName() ?? 'auth'))[0])],
                'summary' => self::summary($route),
                'parameters' => array_map(fn (string $p): array => ['name' => $p, 'in' => 'path', 'required' => ! str_contains($route->uri(), '{'.$p.'?}'), 'schema' => ['type' => in_array($p, ['token', 'notification', 'report', 'key', 'type', 'tag', 'id'], true) ? 'string' : 'integer']], $route->parameterNames()),
                'security' => $public ? [] : [['session' => []]],
                'responses' => self::responses(self::method($route), $public, $signed),
                'x-roles' => $public ? 'public' : $allowed,
                'x-route-matrix' => $codes,
            ];
            if ($idempotent) {
                $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'description' => 'Client-generated UUID (the app UI always sends one; `_idempotency_key` form field also accepted). A replay with the same key returns the first response and never repeats the effect; a concurrent duplicate gets 409 (§5.1).', 'schema' => ['type' => 'string', 'format' => 'uuid']];
            }
            if ($recent) {
                $op['x-requires-recent-auth'] = true;
            }
            if ($signed) {
                $op['x-signed-url'] = true;
            }
            if ($throttle !== []) {
                $op['x-rate-limit'] = $throttle;
            }
            if ($op['parameters'] === []) {
                unset($op['parameters']);
            }
            $paths[$uri][strtolower(self::method($route))] = $op;
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Infernix Workspace — HTTP interface', 'version' => (string) config('infernix.release', '1.0.0-rc1'),
                'description' => 'Generated by `php artisan api:openapi` from the route table and docs/PERMISSION_MATRIX.md. Browser pages are Inertia responses (HTML on first load, JSON with `X-Inertia: true`). See docs/API.md.'],
            'servers' => [['url' => '/']],
            'components' => ['securitySchemes' => ['session' => ['type' => 'apiKey', 'in' => 'cookie', 'name' => (string) config('session.cookie'),
                'description' => 'Laravel session cookie after login + MFA. State-changing requests also need the X-XSRF-TOKEN header (CSRF).']]],
            'paths' => $paths,
        ];
    }

    private static function method(Route $r): string
    {
        $m = array_values(array_diff($r->methods(), ['HEAD']));

        return in_array('GET', $m, true) ? 'GET' : $m[0];
    }

    private static function summary(Route $r): string
    {
        $action = $r->getActionName();
        $short = str_contains($action, '\\') ? substr($action, (int) strrpos($action, '\\') + 1) : $action;

        return str_replace('@', '::', $short);
    }

    /** @return array<int|string, array{description: string}> */
    private static function responses(string $method, bool $public, bool $signed): array
    {
        $r = $method === 'GET' ? ['200' => ['description' => 'Page (Inertia) or JSON / file']] : ['302' => ['description' => 'Redirect back with a flash message (success / error)'], '422' => ['description' => 'Validation errors']];
        if (! $public) {
            $r['401'] = ['description' => 'Not signed in (browser: redirect to /login)'];
            $r['403'] = ['description' => 'Denied by policy / permission matrix'];
            $r['404'] = ['description' => 'Record not found or outside the caller\'s record scope'];
        }
        if ($signed) {
            $r['403'] = ['description' => 'Missing, expired or tampered signature'];
        }
        ksort($r);

        return $r;
    }

    /** @return array<string, array<string, string>> */
    private static function matrix(): array
    {
        $doc = (string) @file_get_contents(base_path('docs/PERMISSION_MATRIX.md'));
        $rows = [];
        foreach (explode("\n", $doc) as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '| ')));
            if (count($cells) === 10 && in_array($cells[1], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $rows[trim($cells[0], '`')] = array_combine(self::ROLES, array_slice($cells, 3, 7));
            }
        }

        return $rows;
    }
}
