<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Search\SearchIndexer;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Kernel\Secrets\RedactSecretsInLogs;
use App\Modules\Credentials\Exceptions\CredentialRejected;
use App\Modules\Credentials\Models\CredentialEntitlement;
use App\Modules\Credentials\Models\CredentialMetadata;
use App\Modules\Credentials\Models\JmlChecklist;
use App\Modules\Credentials\Services\AccessService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Credentials\Vault\FakeVault;
use App\Modules\Credentials\Vault\NullVault;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\HumanResources\Services\StaffService;
use App\Modules\Procurement\Services\SubscriptionService;
use App\Modules\Reporting\Models\Export;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/** @see SRS M13-01 … M13-06, WF-07, UAT-08 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
    app()->instance(VaultClient::class, new FakeVault);
    $this->sentinel = (string) config('infernix.secret_sentinel');
});
afterEach(fn () => Carbon::setTestNow());

/** @param  array<string, mixed>  $overrides */
function makeCredential(array $overrides = [], ?User $owner = null): CredentialMetadata
{
    $owner ??= ownerUser();

    return app(CredentialService::class)->save(null, array_merge(['system_name' => 'AWS root '.Str::random(3), 'category' => 'cloud', 'environment' => 'production', 'owner_id' => $owner->id,
        'vault_provider' => 'bitwarden', 'vault_item_reference' => '0f8f0e2a-1234-4cde-9abc-001122334455', 'sensitivity' => 'critical', 'rotation_interval_days' => 90], $overrides), ownerUser());
}

it('M13-02: credentials_metadata has no column that can hold a secret, and secret-looking input is refused', function (): void {
    $columns = Schema::getColumnListing('credentials_metadata');
    foreach ($columns as $c) {
        expect($c)->not->toMatch('/^(password|passwd|secret|secret_value|api_key|apikey|token|access_token|private_key|client_secret|pin)$/');
    }
    expect($columns)->toContain('vault_item_reference')->not->toContain('password');

    $svc = app(CredentialService::class);
    expect(fn () => makeCredential(['notes' => "password={$this->sentinel}"]))->toThrow(CredentialRejected::class, 'looks like it contains a secret')
        ->and(fn () => makeCredential(['notes' => "key is {$this->sentinel}"]))->toThrow(CredentialRejected::class)
        ->and(fn () => makeCredential(['username_reference' => 'admin token: abc123def']))->toThrow(CredentialRejected::class);
    $ok = makeCredential(['notes' => 'Root account — use only for billing changes']);
    expect($ok->rotation_due?->toDateString())->toBe(today()->addDays(90)->toDateString());
    // Client credentials must be tied to their client; shared service accounts need a justification.
    expect(fn () => makeCredential(['category' => 'client']))->toThrow(CredentialRejected::class, 'segregation')
        ->and(fn () => makeCredential(['is_shared_service_account' => true]))->toThrow(CredentialRejected::class, 'Shared service accounts');
})->group('M13-02', 'M13-01', 'M13-06');

it('M13-03: reveal needs recent re-authentication AND a vault entitlement; it is audited without the value and never cached', function (): void {
    $stf = userWithRoles('STF');
    $cred = makeCredential();
    // Matrix may allow STF reveal:assigned, but without an entitlement it is refused.
    actingAsSatisfied($stf)->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'deploy fix'])->assertForbidden();
    app(AccessService::class)->grant($cred, $stf, ownerUser());

    // No recent re-auth → sent to re-authenticate, nothing revealed or audited.
    $this->flushSession();
    $this->actingAs($stf)->withSession([EnsureMfa::SESSION_KEY => now()->toIso8601String()])
        ->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'deploy fix'])->assertRedirectContains('/reauth');
    expect(AuditEvent::query()->where('action', 'reveal')->where('entity_type', CredentialMetadata::class)->count())->toBe(0);

    actingAsSatisfied($stf)->postJson(route('credentials.credentials.reveal', $cred->id), [])->assertUnprocessable();
    $res = actingAsSatisfied($stf)->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'deploy fix'])->assertOk()->assertJsonPath('value', $this->sentinel);
    expect($res->headers->get('Cache-Control'))->toContain('no-store');
    $audit = AuditEvent::query()->where('action', 'reveal')->where('entity_type', CredentialMetadata::class)->where('entity_id', $cred->id)->sole();
    expect($audit->actor_id)->toBe($stf->id)->and($audit->reason)->toBe('deploy fix')->and(json_encode([$audit->before, $audit->after]))->not->toContain($this->sentinel);

    // Revoked entitlement → refused again.
    app(AccessService::class)->revoke(CredentialEntitlement::query()->where('user_id', $stf->id)->sole(), ownerUser(), 'Project ended');
    actingAsSatisfied($stf)->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'again'])->assertForbidden();

    // Without a configured vault the reveal is impossible; the UI gets the reference/deep link only.
    app()->instance(VaultClient::class, new NullVault);
    actingAsSatisfied(ownerUser())->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'check'])->assertStatus(409)->assertJsonMissingPath('value');
})->group('M13-03', 'UAT-08', 'NFR-03');

it('M13-06: a PM never sees client credentials of other clients — not in the list, the record, or search', function (): void {
    $pm = userWithRoles('PM');
    $mine = makeProject($pm);
    $other = makeProject();
    $ok = makeCredential(['system_name' => 'Client Alpha Power BI', 'category' => 'client', 'client_id' => $mine->client_id]);
    $no = makeCredential(['system_name' => 'Client Beta Power BI', 'category' => 'client', 'client_id' => $other->client_id]);
    makeCredential(['system_name' => 'Company Xero']);

    actingAsSatisfied($pm)->get(route('credentials.credentials.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->has('credentials', 1)->where('credentials.0.id', $ok->id));
    actingAsSatisfied($pm)->get(route('credentials.credentials.show', $no->id))->assertForbidden();
    actingAsSatisfied($pm)->get(route('credentials.credentials.show', $ok->id))->assertOk();
    $hits = actingAsSatisfied($pm)->getJson(route('search', ['q' => 'Power BI']))->assertOk()->json('results');
    expect(collect($hits)->pluck('id')->all())->toBe([$ok->id]);
    expect(collect(actingAsSatisfied(ownerUser())->getJson(route('search', ['q' => 'Power BI']))->json('results'))->count())->toBe(2);
    // The credential register export is row-scoped too.
    $rows = app(ReportCatalog::class)->find('credentials.register')?->run([], $pm)['rows'];
    expect(collect($rows)->pluck('system_name')->all())->toBe(['Client Alpha Power BI']);
})->group('M13-06', 'NFR-02', 'G-04');

it('WF-07 / M13-04: request → owner approval (not self) → entitlement → leaver offboarding revokes it and opens a checklist', function (): void {
    $ownerOfCred = userWithRoles('PM');
    $requester = userWithRoles('STF');
    $staff = makeStaff($requester);
    $project = makeProject($ownerOfCred, [$staff]);
    $cred = makeCredential(['system_name' => 'GitHub deploy key', 'category' => 'development', 'project_id' => $project->id], $ownerOfCred);

    actingAsSatisfied($requester)->get(route('credentials.credentials.show', $cred->id))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('can.request', true)->where('can.reveal', false));
    actingAsSatisfied($requester)->post(route('credentials.credentials.request', $cred->id), ['justification' => 'Need to deploy the client hotfix'])->assertSessionHasNoErrors();
    $req = $cred->accessRequests()->sole();
    actingAsSatisfied($requester)->post(route('credentials.requests.decide', $req->id), ['approve' => true])->assertForbidden();
    actingAsSatisfied(userWithRoles('PM'))->post(route('credentials.requests.decide', $req->id), ['approve' => true])->assertForbidden(); // not the resource owner
    actingAsSatisfied($ownerOfCred)->post(route('credentials.requests.decide', $req->id), ['approve' => true, 'note' => 'ok'])->assertSessionHasNoErrors();
    expect($cred->hasActiveEntitlement($requester))->toBeTrue();
    actingAsSatisfied($requester)->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'hotfix'])->assertOk();

    // Leaver: status → inactive revokes every entitlement at once and opens the leaver checklist.
    app(StaffService::class)->update($staff->fresh(), ['status' => 'inactive', 'ended_on' => today()->toDateString()], ownerUser());
    expect($cred->fresh()->hasActiveEntitlement($requester))->toBeFalse()
        ->and(CredentialEntitlement::query()->where('user_id', $requester->id)->sole()->revoke_reason)->toContain('Offboarding');
    $checklist = JmlChecklist::query()->where('kind', 'leaver')->where('user_id', $requester->id)->sole();
    expect(collect($checklist->items)->firstWhere('key', 'revoked')['done'])->toBeTrue()->and($checklist->status)->toBe('open');
    actingAsSatisfied(userWithRoles('HR'))->get(route('credentials.jml.index'))->assertOk();
    actingAsSatisfied(userWithRoles('HR'))->post(route('credentials.jml.tick', $checklist->id), ['key' => 'rotate'])->assertSessionHasNoErrors();
    actingAsSatisfied(userWithRoles('STF'))->get(route('credentials.jml.index'))->assertForbidden();
    // The credential access audit lists the reveal and the grant/revoke trail — no values.
    $audit = actingAsSatisfied(ownerUser())->get(route('credentials.audit'))->assertOk();
    expect($audit->getContent())->toContain('entitlement_revoke')->toContain('reveal')->not->toContain($this->sentinel);
    // Joiner checklist was opened when the staff profile was created.
    expect(JmlChecklist::query()->where('kind', 'joiner')->where('staff_profile_id', $staff->id)->exists())->toBeTrue();
})->group('WF-07', 'M13-04', 'M13-03');

it('UAT-08: the secret sentinel never leaks into an export, log line, notification or the search index', function (): void {
    // 1) A reveal returns it to the caller only.
    $cred = makeCredential(['system_name' => 'Leak test API']);
    $logPath = storage_path('logs/leak-test-'.Str::random(6).'.log');
    config(['logging.channels.leaktest' => ['driver' => 'single', 'path' => $logPath, 'level' => 'debug', 'tap' => [RedactSecretsInLogs::class]], 'logging.default' => 'leaktest']);
    Log::forgetChannel('leaktest');
    actingAsSatisfied(ownerUser())->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'leak test'])->assertOk()->assertJsonPath('value', $this->sentinel);

    // 2) Someone pastes the secret into free-text fields elsewhere (not a credential form).
    $vendor = makeVendor('Sentinel test vendor');
    $sub = app(SubscriptionService::class)->save(null, ['vendor_id' => $vendor->id, 'product' => "API plan key={$this->sentinel}", 'seats' => 1, 'price' => '10.00', 'currency' => 'MYR', 'billing_cycle' => 'monthly',
        'renewal_date' => today()->addDays(10)->toDateString(), 'notes' => "admin password: {$this->sentinel}"], ownerUser());
    app(SearchIndexer::class)->index('vendor', $vendor->id, "{$vendor->name} {$this->sentinel}", null, 'Procurement');
    app(SubscriptionService::class)->runRenewalAlerts();                       // notifications (database + mail)
    Log::info("debug dump {$this->sentinel}", ['payload' => ['secret' => $this->sentinel]]);
    Log::error('token='.$this->sentinel);
    actingAsSatisfied(ownerUser())->post(route('reports.exports.store'), ['report' => 'procurement.renewal-calendar', 'format' => 'csv', 'from' => today()->toDateString(), 'to' => today()->addYear()->toDateString()])->assertRedirect();
    $csv = (string) Storage::disk(config('infernix.documents.disk'))->get(Document::query()->findOrFail(Export::query()->latest('id')->value('document_id'))->versions()->firstOrFail()->storage_key);

    expect($csv)->toContain('API plan')->not->toContain($this->sentinel)
        ->and(DB::table('search_index')->get()->toJson())->toContain('Sentinel test vendor')->not->toContain($this->sentinel)
        ->and(DB::table('notifications')->count())->toBeGreaterThan(0)->and(DB::table('notifications')->get()->toJson())->not->toContain($this->sentinel)
        ->and(DB::table('audit_events')->get()->toJson())->not->toContain($this->sentinel)
        ->and(file_get_contents($logPath))->toContain('[SECRET-REDACTED]')->not->toContain($this->sentinel);
    expect(collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->toString())->implode("\n"))->not->toContain($this->sentinel);
    @unlink($logPath);
    expect($sub->fresh())->not->toBeNull();
})->group('UAT-08', 'M13-05', 'M13-02');
