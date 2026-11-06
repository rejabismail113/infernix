<?php

declare(strict_types=1);

use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Search\SearchIndexer;
use App\Kernel\Secrets\Contracts\VaultClient;
use App\Kernel\Secrets\RedactSecretsInLogs;
use App\Modules\Administration\Models\ClaimType;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Credentials\Vault\FakeVault;
use App\Modules\Finance\Services\ClaimService;
use App\Modules\Procurement\Services\SubscriptionService;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\ScheduledReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Files;

/*
 * Secret sentinel sweep (UAT-08, M13-02, M13-05). The fake sentinel is revealed through the vault and pasted into
 * every kind of free-text field a user could misuse; then the WHOLE database (every row of every table, as text —
 * the same content a pg_dump would carry), every catalog export, every log channel, all mail and the search
 * index are searched for it. The reveal response itself is the only place it may appear.
 */
it('UAT-08: the secret sentinel appears nowhere after reveals, paste attempts, exports, logs, mail and indexing', function (): void {
    $sentinel = (string) config('infernix.secret_sentinel');
    expect($sentinel)->toStartWith('SENTINEL-DO-NOT-LEAK-');
    Storage::fake(config('infernix.documents.disk'));
    app()->instance(VaultClient::class, new FakeVault);
    $owner = ownerUser();

    // Every log channel writes to a scratch file through the real channel config (taps included).
    $logDir = storage_path('logs/sweep-'.Str::random(6));
    @mkdir($logDir);
    foreach (['single', 'daily', 'json'] as $channel) {
        config(["logging.channels.{$channel}.path" => "{$logDir}/{$channel}.log"]);
        Log::forgetChannel($channel);
        expect(config("logging.channels.{$channel}.tap"))->toContain(RedactSecretsInLogs::class);
    }

    // 1) Legitimate reveal: the caller (and only the caller) receives it.
    $cred = app(CredentialService::class)->save(null, ['system_name' => 'Sweep API', 'category' => 'cloud', 'environment' => 'production', 'owner_id' => $owner->id,
        'vault_provider' => 'bitwarden', 'vault_item_reference' => '0f8f0e2a-1234-4cde-9abc-001122334455', 'sensitivity' => 'critical'], $owner);
    actingAsSatisfied($owner)->postJson(route('credentials.credentials.reveal', $cred->id), ['reason' => 'sweep'])->assertOk()->assertJsonPath('value', $sentinel);

    // 2) Paste attempts in free text across modules (stored scrubbed by ScrubSecretsOnSave, or refused).
    $vendor = makeVendor("Vendor {$sentinel}");
    $sub = app(SubscriptionService::class)->save(null, ['vendor_id' => $vendor->id, 'product' => "Plan api_key={$sentinel}", 'seats' => 1, 'price' => '10.00', 'currency' => 'MYR', 'billing_cycle' => 'monthly',
        'renewal_date' => today()->addDays(7)->toDateString(), 'notes' => "admin password: {$sentinel}"], $owner);
    $stf = userWithRoles('STF');
    app(ClaimService::class)->create($stf, ['purpose' => "Taxi token={$sentinel}", 'lines' => [['claim_type_id' => (int) ClaimType::query()->where('code', 'mileage')->value('id'),
        'incurred_on' => today()->toDateString(), 'description' => $sentinel, 'distance_km' => '5']]], $stf);
    storeDocument($owner, ['title' => "Scan {$sentinel}", 'description' => "secret: {$sentinel}"]);
    makeClient(['name' => "Client {$sentinel}", 'contact_person' => "pwd: {$sentinel}", 'address' => ['line1' => "portal password={$sentinel}", 'city' => 'KL']]);
    actingAsSatisfied($owner)->put(route('credentials.credentials.update', $cred->id), ['system_name' => 'Sweep API', 'category' => 'cloud', 'environment' => 'production', 'owner_id' => $owner->id,
        'vault_provider' => 'bitwarden', 'vault_item_reference' => '0f8f0e2a-1234-4cde-9abc-001122334455', 'sensitivity' => 'critical', 'notes' => "the key is {$sentinel}"]);
    actingAsSatisfied($owner)->post(route('admin.imports.run', 'vendors'), ['file' => Files::csv('v.csv', "name,email,notes\nSweep vendor,a@b.example,password={$sentinel}\n"), 'dry_run' => '0']);
    app(SearchIndexer::class)->index('vendor', $vendor->id, "{$vendor->name} {$sentinel}", null, 'Procurement');

    // 3) Logs, notifications + mail, scheduled report mail, then every catalog export.
    Log::info("dump {$sentinel}", ['payload' => ['secret' => $sentinel]]);
    Log::channel('json')->warning('token='.$sentinel, ['nested' => ['k' => $sentinel]]);
    Log::channel('daily')->error("Authorization: Bearer {$sentinel}");
    app(SubscriptionService::class)->runRenewalAlerts();
    ReportSchedule::query()->create(['report_key' => 'procurement.renewal-calendar', 'format' => 'csv', 'frequency' => 'daily', 'recipient_ids' => [$owner->id], 'active' => true, 'created_by' => $owner->id]);
    app(ScheduledReportService::class)->runDue();
    $exported = 0;
    foreach (app(ReportCatalog::class)->all() as $report) {
        Cache::flush(); // the export throttle is per user; this sweep deliberately exports everything
        $res = actingAsSatisfied($owner)->post(route('reports.exports.store'), ['report' => $report->key, 'format' => 'csv', 'from' => '2020-01-01', 'to' => today()->addYear()->toDateString(),
            'as_of' => today()->toDateString(), 'basis' => 'accrual']);
        $exported += $res->isRedirect() && ! session()->has('errors') ? 1 : 0;
        $this->flushSession();
    }
    expect($exported)->toBe(count(app(ReportCatalog::class)->all()));

    // ---- Sweep ----
    $leaks = [];
    // a) Database: every row of every table in every application schema, rendered as text (≈ pg_dump content).
    $tables = DB::select("SELECT table_schema, table_name FROM information_schema.tables WHERE table_schema IN ('public', 'reporting') ORDER BY 1, 2");
    foreach ($tables as $t) {
        $hit = DB::selectOne("SELECT count(*) AS n FROM \"{$t->table_schema}\".\"{$t->table_name}\" x WHERE x::text LIKE ?", ['%'.$sentinel.'%']);
        if ((int) $hit->n > 0) {
            $leaks[] = "db:{$t->table_schema}.{$t->table_name}";
        }
    }
    // b) Every stored file (exports, uploads, rendered PDFs).
    $disk = Storage::disk(config('infernix.documents.disk'));
    foreach ($disk->allFiles() as $file) {
        if (str_contains((string) $disk->get($file), $sentinel)) {
            $leaks[] = "file:{$file}";
        }
    }
    // c) Logs.
    foreach (glob("{$logDir}/*") ?: [] as $file) {
        if (str_contains((string) file_get_contents($file), $sentinel)) {
            $leaks[] = 'log:'.basename($file);
        }
    }
    // d) Mail.
    foreach (app('mailer')->getSymfonyTransport()->messages() as $i => $m) {
        if (str_contains($m->toString(), $sentinel)) {
            $leaks[] = "mail:{$i}";
        }
    }

    expect($leaks)->toBe([])
        ->and(DB::table('search_index')->count())->toBeGreaterThan(0)
        ->and(DB::table('subscriptions')->where('id', $sub->id)->value('notes'))->toContain('[SECRET-REDACTED]')
        ->and(count(glob("{$logDir}/*") ?: []))->toBeGreaterThanOrEqual(2)
        ->and(app('mailer')->getSymfonyTransport()->messages()->count())->toBeGreaterThan(0);

    array_map('unlink', glob("{$logDir}/*") ?: []);
    @rmdir($logDir);
})->group('UAT-08', 'M13-02', 'M13-05', 'NFR-03');
