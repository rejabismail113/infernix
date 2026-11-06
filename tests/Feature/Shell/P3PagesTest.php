<?php

declare(strict_types=1);

use App\Modules\Activities\Services\ActivityService;
use App\Modules\Assets\Services\AssetService;
use App\Modules\Certifications\Services\CertificationService;
use App\Modules\Credentials\Services\CredentialService;
use App\Modules\Csr\Services\CsrService;
use App\Modules\Procurement\Services\PurchaseRequestService;
use Illuminate\Support\Facades\Storage;

/** @see SRS M05, M07, M08, M09, M10, M13, NFR-02 */
it('every P3 page renders for the Owner and denies roles without rights', function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $owner = ownerUser();
    $asset = app(AssetService::class)->registerManual(['acquisition_type' => 'donation', 'name' => 'Camera', 'cost' => '2500.00', 'acquired_on' => '2026-01-01'], $owner);
    $activity = app(ActivityService::class)->create(['type' => 'event', 'title' => 'Launch', 'start_date' => '2026-03-01', 'end_date' => '2026-03-01'], $owner);
    $csr = app(CsrService::class)->create(['title' => 'Food bank', 'programme_date' => '2026-04-01'], $owner);
    app(CertificationService::class)->saveCertification(null, ['holder_type' => 'company', 'name' => 'MyIPO', 'issuer' => 'MyIPO', 'no_expiry' => true], $owner);
    $vendor = makeVendor();
    $pr = app(PurchaseRequestService::class)->save(null, ['title' => 'Paper', 'justification' => 'Stock', 'lines' => [['description' => 'A4', 'quantity' => '10', 'estimated_unit_price' => '12.00']]], $owner);
    $cred = app(CredentialService::class)->save(null, ['system_name' => 'Xero', 'category' => 'corporate', 'environment' => 'production', 'owner_id' => $owner->id, 'vault_provider' => 'none', 'sensitivity' => 'high'], $owner);
    $pages = [
        route('assets.assets.index'), route('assets.assets.show', $asset->id), route('activities.activities.index'), route('activities.activities.show', $activity->id),
        route('csr.programmes.index'), route('csr.programmes.show', $csr->id), route('certifications.certifications.index'), route('certifications.certifications.index', ['tab' => 'renewals']),
        route('procurement.vendors.index'), route('procurement.vendors.show', $vendor->id), route('procurement.requests.index'), route('procurement.requests.show', $pr->id),
        route('procurement.subscriptions.index'), route('credentials.credentials.index'), route('credentials.credentials.show', $cred->id), route('credentials.audit'), route('credentials.jml.index'),
        route('search', ['q' => 'Camera']), route('reports.index'),
        route('reports.view', 'assets.register'), route('reports.view', 'activities.register'), route('reports.view', 'activities.trip-cost'), route('reports.view', 'csr.spend'), route('reports.view', 'csr.beneficiaries'),
        route('reports.view', 'certifications.expiring'), route('reports.view', 'certifications.expenditure'), route('reports.view', 'procurement.vendor-spending'), route('reports.view', 'procurement.pipeline'),
        route('reports.view', 'procurement.renewal-calendar'), route('reports.view', 'credentials.register'),
    ];
    foreach ($pages as $url) {
        expect(actingAsSatisfied($owner)->get($url)->status())->toBe(200, $url);
    }
    // Deny-by-default spot checks.
    $apr = userWithRoles('APP');
    foreach ([route('assets.assets.index'), route('csr.programmes.index'), route('credentials.audit'), route('procurement.subscriptions.index'), route('credentials.credentials.show', $cred->id)] as $url) {
        expect(actingAsSatisfied($apr)->get($url)->status())->toBe(403, $url);
    }
    $aud = userWithRoles('AUD');
    expect(actingAsSatisfied($aud)->get(route('credentials.credentials.show', $cred->id))->status())->toBe(200)
        ->and(actingAsSatisfied($aud)->post(route('credentials.credentials.store'), ['system_name' => 'x'])->status())->toBe(403);
    // Search never returns what the user cannot open.
    expect(actingAsSatisfied(userWithRoles('STF'))->getJson(route('search', ['q' => 'Camera']))->json('results'))->toBe([]);
})->group('NFR-02', 'G-04', 'M05-01', 'M07-01', 'M08-01', 'M09-01', 'M10-01', 'M13-01');
