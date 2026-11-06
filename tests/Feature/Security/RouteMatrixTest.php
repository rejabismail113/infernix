<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\RouteMatrix;

/*
 * Route × role matrix generated from / checked against docs/PERMISSION_MATRIX.md (NFR-02). Every registered
 * route must have a row; every role's outcome must match the documented one; no route may 500. Records belong
 * to other users, so the STF / APP columns double as the IDOR check for every {id} route.
 *
 * Regenerate after a deliberate change:  ROUTE_MATRIX_WRITE=1 php artisan test tests/Feature/Security/RouteMatrixTest.php
 */
it('matches the documented route x role matrix for every registered route', function (): void {
    $fixtures = RouteMatrix::fixtures();
    $users = ['OWN' => ownerUser()];
    foreach (array_slice(RouteMatrix::ROLES, 1) as $role) {
        $users[$role] = userWithRoles($role);
    }

    $observed = [];
    $errors = [];
    foreach (RouteMatrix::routes() as $route) {
        $key = RouteMatrix::key($route);
        $method = RouteMatrix::method($route);
        $url = RouteMatrix::url($route, $fixtures);
        $codes = [];
        foreach ($users as $role => $user) {
            Cache::flush();
            $this->flushSession();
            actingAsSatisfied($user);
            DB::beginTransaction();
            try {
                $status = $this->call($method, $url, [], [], [], ['HTTP_ACCEPT' => 'text/html'])->getStatusCode();
                $session = app('session.store');
                $bag = $session->get('errors');
                $errors = $bag instanceof ViewErrorBag ? $bag->getBag('default')->toArray() : [];
                $flashError = $session->has('error');
            } finally {
                DB::rollBack();
            }
            $codes[$role] = RouteMatrix::classify($status, $errors, $flashError);
            if ($codes[$role] === 'X') {
                $errors[] = "{$key} {$method} {$url} as {$role} → {$status}";
            }
        }
        $observed[$key] = ['method' => $method, 'uri' => $route->uri(), 'codes' => $codes];
    }

    expect($errors)->toBe([], 'Routes returned a server error');

    if (getenv('ROUTE_MATRIX_WRITE') === '1') {
        RouteMatrix::writeDoc($observed);
        $this->markTestSkipped('Route matrix regenerated in docs/PERMISSION_MATRIX.md — review the diff.');
    }

    $documented = RouteMatrix::parseDoc();
    $missing = array_values(array_diff(array_keys($observed), array_keys($documented)));
    $stale = array_values(array_diff(array_keys($documented), array_keys($observed)));
    expect($missing)->toBe([], 'Registered routes with no row in docs/PERMISSION_MATRIX.md')
        ->and($stale)->toBe([], 'Rows in docs/PERMISSION_MATRIX.md for routes that no longer exist');

    $drift = [];
    foreach ($observed as $key => $row) {
        if ($documented[$key]['codes'] !== $row['codes']) {
            $drift[] = $key.' documented '.implode('', $documented[$key]['codes']).' observed '.implode('', $row['codes']);
        }
    }
    expect($drift)->toBe([], 'Route matrix drift (order OWN FIN PM HR STF AUD APP)');
})->group('NFR-02', 'UAT-01', 'UAT-10', 'M06-02', 'M12-02');

it('never lets a plain staff member or an applicant reach another person\'s record by id', function (): void {
    $documented = RouteMatrix::parseDoc();
    expect($documented)->not->toBeEmpty();
    $leaks = [];
    foreach ($documented as $key => $row) {
        if (! str_contains($row['uri'], '{') || in_array($key, RouteMatrix::IDOR_ALLOW, true)) {
            continue;
        }
        foreach (['STF', 'APP'] as $role) {
            if (in_array($row['codes'][$role], ['A', 'V'], true)) {
                $leaks[] = "{$key} ({$role})";
            }
        }
    }
    expect($leaks)->toBe([]);
})->group('NFR-02', 'UAT-01', 'UAT-10');
