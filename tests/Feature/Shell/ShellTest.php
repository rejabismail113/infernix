<?php

declare(strict_types=1);

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\LinkTarget;
use Illuminate\Support\Facades\Storage;

/** @see SRS NFR-02, NFR-08, NFR-09, G-04, M06-04 */
it('renders the Inertia 403 page for forbidden routes, with the 403 status kept', function (): void {
    $res = actingAsSatisfied(userWithRoles('STF'))->get(route('admin.audit-log.index'))->assertForbidden();
    expect($res->viewData('page')['component'])->toBe('Errors/Show')
        ->and($res->viewData('page')['props']['status'])->toBe(403);

    // A JSON client still gets a JSON 403, not an HTML page.
    actingAsSatisfied(userWithRoles('STF'))->getJson(route('admin.audit-log.index'))->assertForbidden()->assertJsonStructure(['message']);
})->group('NFR-02', 'NFR-09');

it('renders the Inertia 404 page for missing records', function (): void {
    $res = actingAsSatisfied(ownerUser())->get('/admin/users/999999')->assertNotFound();
    expect($res->viewData('page')['component'])->toBe('Errors/Show');
})->group('NFR-09');

it('sends a grouped, icon-tagged sidebar that differs by role (server-side filtering)', function (): void {
    $navFor = fn (string $role) => collect(actingAsSatisfied(userWithRoles($role))->get('/')->assertOk()->viewData('page')['props']['nav']);

    $own = $navFor('OWN');
    expect($own->pluck('group')->unique()->values()->all())->toBe(['Overview', 'Finance', 'Procurement', 'Documents', 'Administration'])
        ->and($own->every(fn (array $i): bool => $i['icon'] !== ''))->toBeTrue()
        ->and($own->pluck('label')->all())->toContain('Audit log', 'Permissions', 'Documents');

    $stf = $navFor('STF');
    expect($stf->pluck('label')->all())->toContain('Dashboard', 'Documents', 'Users', 'Claims')
        ->not->toContain('Bills', 'Payments', 'Journals', 'Finance reports')
        ->not->toContain('Audit log', 'Permissions', 'Numbering', 'Fiscal periods');
    // …and the hidden entries are still enforced by the routes themselves.
    foreach (['admin.audit-log.index', 'admin.permissions.index', 'admin.numbering.index', 'admin.fiscal-periods.index'] as $route) {
        actingAsSatisfied(userWithRoles('STF'))->get(route($route))->assertForbidden();
    }
})->group('NFR-02', 'G-02');

it('sorts and paginates the audit log on the server with a whitelist', function (): void {
    $owner = ownerUser();
    foreach (['zeta', 'alpha', 'mid'] as $a) {
        Audit::log($a, 'Thing', 1);
    }
    $page = actingAsSatisfied($owner)->get(route('admin.audit-log.index', ['sort' => 'action', 'dir' => 'asc', 'per_page' => 10]))->assertOk()->viewData('page')['props']['events'];
    expect($page['per_page'])->toBe(10)
        ->and(collect($page['data'])->pluck('action')->take(1)->all())->toBe(['alpha']);

    actingAsSatisfied($owner)->get(route('admin.audit-log.index', ['sort' => 'before; drop table users']))->assertSessionHasErrors('sort');
    actingAsSatisfied($owner)->get(route('admin.audit-log.index', ['per_page' => 5000]))->assertSessionHasErrors('per_page');
})->group('M12-04', 'NFR-05');

it('sorts documents on the server without widening visibility', function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $staff = userWithRoles('STF');
    storeDocument($staff, ['title' => 'Bravo']);
    storeDocument($staff, ['title' => 'Alpha']);
    storeDocument(userWithRoles('STF'), ['title' => 'Aardvark (not mine)']);

    $titles = fn (string $dir) => collect(actingAsSatisfied($staff)->get(route('documents.index', ['sort' => 'title', 'dir' => $dir]))->assertOk()->viewData('page')['props']['documents']['data'])->pluck('title')->all();
    expect($titles('asc'))->toBe(['Alpha', 'Bravo'])
        ->and($titles('desc'))->toBe(['Bravo', 'Alpha']);
    actingAsSatisfied($staff)->get(route('documents.index', ['sort' => 'storage_key']))->assertSessionHasErrors('sort');
})->group('M06-04', 'NFR-02');

it('the link picker only offers records the user may attach documents to', function (): void {
    $this->getJson(route('documents.link-targets', ['type' => 'user']))->assertUnauthorized();
    $staff = userWithRoles('STF');
    $other = userWithRoles('STF');

    $items = actingAsSatisfied($staff)->getJson(route('documents.link-targets', ['type' => 'user']))->assertOk()->json('items');
    expect(collect($items)->pluck('id')->all())->toBe([$staff->id]);
    expect(actingAsSatisfied($staff)->getJson(route('documents.link-targets', ['type' => 'company']))->assertOk()->json('items'))->toBe([]);

    $hrItems = actingAsSatisfied(userWithRoles('HR'))->getJson(route('documents.link-targets', ['type' => 'user', 'q' => $other->name]))->assertOk()->json('items');
    expect(collect($hrItems)->pluck('id')->all())->toContain($other->id);

    actingAsSatisfied($staff)->getJson(route('documents.link-targets', ['type' => 'App\\Kernel\\Identity\\Models\\User']))->assertUnprocessable();
    actingAsSatisfied(userWithRoles('AUD'))->getJson(route('documents.link-targets', ['type' => 'user']))->assertForbidden();
})->group('M06-01', 'NFR-02', 'G-11');

it('every restyled page renders for the Owner without server errors', function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $owner = ownerUser();
    $doc = storeDocument($owner, [], new LinkTarget('user', $owner->id, 'attachment'));
    $routes = [
        route('dashboard'), route('admin.users.index'), route('admin.users.show', $owner), route('admin.permissions.index'),
        route('admin.delegations.index'), route('admin.company.edit'), route('admin.fiscal-periods.index'), route('admin.numbering.index'),
        route('admin.dictionaries.index'), route('admin.notification-rules.index'), route('admin.audit-log.index'),
        route('admin.reports.user-access'), route('admin.reports.policy-history'), route('documents.index'), route('documents.show', $doc),
        route('documents.reports.inventory'), route('documents.reports.recent-changes'), route('documents.reports.missing-evidence'),
    ];
    foreach ($routes as $url) {
        actingAsSatisfied($owner)->get($url)->assertOk();
    }
})->group('NFR-08', 'NFR-09');
