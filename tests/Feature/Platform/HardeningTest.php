<?php

declare(strict_types=1);

use App\Modules\Administration\Services\BackupService;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS NFR-01, NFR-04, NFR-05, NFR-06, NFR-07, NFR-10, NFR-12, M12-06 */
it('NFR-01: every response carries the security headers and a CSP nonce; authenticated pages are no-store', function (): void {
    $res = $this->get('/login')->assertOk();
    $csp = (string) $res->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9]+'/");
    preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
    expect($res->getContent())->toContain('nonce="'.$m[1].'"'); // the inline Ziggy routes script carries the nonce
    foreach (['X-Frame-Options' => 'DENY', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Cross-Origin-Opener-Policy' => 'same-origin'] as $h => $v) {
        expect($res->headers->get($h))->toBe($v);
    }
    expect((string) actingAsSatisfied(ownerUser())->get('/')->headers->get('Cache-Control'))->toContain('no-store');
    expect(config('session.http_only'))->toBeTrue()->and(config('session.encrypt'))->toBeTrue()->and(config('session.same_site'))->toBe('lax');
})->group('NFR-01');

it('NFR-01: login is rate limited', function (): void {
    foreach (range(1, 10) as $i) {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);
    }
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])->assertStatus(429);
})->group('NFR-01');

it('NFR-10: a request id is generated (or a safe inbound one kept), returned and written to audit events', function (): void {
    $res = $this->get('/login');
    expect($res->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/');
    expect($this->withHeaders(['X-Request-Id' => '0f8f0e2a-1234-4cde-9abc-001122334455'])->get('/login')->headers->get('X-Request-Id'))->toBe('0f8f0e2a-1234-4cde-9abc-001122334455')
        ->and($this->withHeaders(['X-Request-Id' => 'not-a-uuid'])->get('/login')->headers->get('X-Request-Id'))->not->toBe('not-a-uuid');
    expect($this->withHeaders(['X-Request-Id' => "bad id\nInjected: 1"])->get('/login')->headers->get('X-Request-Id'))->not->toContain('Injected');
    actingAsSatisfied(ownerUser())->withHeaders(['X-Request-Id' => 'aaaaaaaa-1234-4cde-9abc-001122334455'])->post(route('procurement.vendors.store'), ['name' => 'Traced vendor'])->assertRedirect();
    expect(DB::table('audit_events')->where('entity_type', Vendor::class)->latest('id')->value('request_id'))->toBe('aaaaaaaa-1234-4cde-9abc-001122334455');
})->group('NFR-10', 'NFR-03');

it('NFR-04/NFR-10: health endpoints report liveness and readiness without leaking details', function (): void {
    $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok']);
    $ready = $this->getJson('/health/ready')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('checks.database', 'ok');
    expect(json_encode($ready->json()))->not->toContain(config('database.connections.pgsql.host'))->not->toContain('postgres')
        ->and($ready->json('warnings'))->toContain('backup_older_than_24h');
})->group('NFR-04', 'NFR-10');

it('NFR-06 / M12-06: nightly backup is encrypted at rest, checksummed, restorable and retention-pruned; refuses without a passphrase', function (): void {
    Storage::fake('backups_local');
    config(['infernix.backup.disk' => 'backups_local', 'infernix.backup.passphrase' => 'test-only-passphrase-0123456789']);
    $svc = app(BackupService::class);
    $id = $svc->run();
    $run = DB::table('backup_runs')->find($id);
    expect($run->status)->toBe('ok')->and($run->sha256)->toHaveLength(64);
    $bytes = (string) Storage::disk('backups_local')->get($run->object_key);
    expect(substr($bytes, 0, 8))->toBe('Salted__')->and($bytes)->not->toContain('PGDMP')->and($bytes)->not->toContain('journal_lines'); // encrypted, not a raw dump

    $test = DB::table('backup_runs')->find($svc->restoreTest());
    expect($test->status)->toBe('ok', (string) $test->error)->and(json_decode((string) $test->details, true)['checks']['journals_balanced']['ok'])->toBeTrue();
    expect($svc->status())->toMatchArray(['rpo_ok' => true, 'restore_test_ok' => true]);

    // Tampering is detected before restore.
    Storage::disk('backups_local')->put($run->object_key, $bytes.'x');
    expect(DB::table('backup_runs')->find($svc->restoreTest())->error)->toContain('Checksum mismatch');

    // Retention: a 20-day-old weekday backup is pruned; a 20-day-old Sunday backup is kept.
    $old = now()->subDays(20);
    while ($old->isSunday()) {
        $old->subDay();
    }
    $sunday = now()->subDays(20)->previous('Sunday');
    foreach ([[$old, 'old.enc'], [$sunday, 'sunday.enc']] as [$at, $key]) {
        Storage::disk('backups_local')->put($key, 'x');
        DB::table('backup_runs')->insert(['kind' => 'backup', 'status' => 'ok', 'started_at' => $at, 'object_key' => $key]);
    }
    expect($svc->prune())->toBe(1)->and(Storage::disk('backups_local')->exists('old.enc'))->toBeFalse()->and(Storage::disk('backups_local')->exists('sunday.enc'))->toBeTrue();

    config(['infernix.backup.passphrase' => '']);
    expect(DB::table('backup_runs')->find($svc->run())->error)->toContain('BACKUP_PASSPHRASE');
    actingAsSatisfied(ownerUser())->get(route('admin.backups.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('rpoOk', true));
    actingAsSatisfied(userWithRoles('FIN'))->get(route('admin.backups.index'))->assertForbidden();
    actingAsSatisfied(userWithRoles('AUD'))->post(route('admin.backups.run'), ['kind' => 'backup'])->assertForbidden();
})->group('NFR-06', 'M12-06');

it('NFR-07: restricted fields are encrypted at rest (raw column never holds the plaintext)', function (): void {
    $staff = makeStaff();
    app(StaffService::class)->setBank($staff, ['bank_name' => 'CIMB', 'account_no' => '800012345678', 'account_name' => 'X'], ownerUser());
    $vendor = makeVendor();
    $vendor->forceFill(['bank_reference' => '5140 9988 7766'])->save();
    expect((string) DB::table('staff_profiles')->where('id', $staff->id)->value('id_number'))->not->toContain('900101')
        ->and((string) DB::table('staff_bank_details')->where('staff_profile_id', $staff->id)->value('account_no'))->not->toContain('800012345678')
        ->and((string) DB::table('vendors')->where('id', $vendor->id)->value('bank_reference'))->not->toContain('9988');
})->group('NFR-07', 'M04-06');

it('NFR-12: users switch the interface between English and Bahasa Melayu', function (): void {
    $u = userWithRoles('FIN');
    actingAsSatisfied($u)->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('i18n.locale', 'en')->where('nav.0.label', 'Dashboard'));
    actingAsSatisfied($u)->post(route('locale.update'), ['locale' => 'ms'])->assertRedirect();
    expect($u->fresh()->locale)->toBe('ms');
    actingAsSatisfied($u->fresh())->get('/')->assertInertia(fn (AssertableInertia $p) => $p->where('i18n.locale', 'ms')->where('nav.0.label', 'Papan pemuka')
        ->where('i18n.messages.Log out', 'Log keluar'));
    actingAsSatisfied($u)->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $ms = json_decode((string) file_get_contents(lang_path('ms.json')), true);
    expect(array_keys($ms))->toBe(array_keys($en)); // every key has both languages
})->group('NFR-12');

it('NFR-05: the benchmark command runs end to end and leaves no data behind', function (): void {
    $before = DB::table('transactions')->count();
    expect(Artisan::call('perf:benchmark', ['--rows' => 1000, '--iterations' => 3]))->toBe(0);
    expect(DB::table('transactions')->count())->toBe($before);
})->group('NFR-05');
