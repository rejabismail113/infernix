<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\LinkTarget;
use App\Modules\Administration\Models\Company;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M06 outputs, M11-05, G-08, UAT-08 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('missing-evidence report lists records lacking required documents, honours row permissions, and clears once linked', function (): void {
    $owner = ownerUser();
    $company = Company::current();

    $rows = collect(actingAsSatisfied($owner)->get(route('documents.reports.missing-evidence'))->assertOk()->viewData('page')['props']['rows']);
    $ssm = $rows->firstWhere('link_type', 'ssm_registration');
    expect($ssm['status'])->toBe('missing')
        ->and(collect($ssm['missing'])->pluck('entity_id')->all())->toBe([$company->id])
        ->and($rows->firstWhere('entity_type', 'asset')['status'])->toBe('pending_module')
        ->and($rows->firstWhere('entity_type', 'claim_line')['status'])->toBe('complete');

    // STF cannot see the company record, so nothing about it is reported to them.
    $staffRows = collect(actingAsSatisfied(userWithRoles('STF'))->get(route('documents.reports.missing-evidence'))->assertOk()->viewData('page')['props']['rows']);
    expect($staffRows->firstWhere('link_type', 'ssm_registration')['missing'])->toBe([]);

    storeDocument($owner, ['title' => 'SSM cert'], new LinkTarget('company', $company->id, 'ssm_registration'));
    $rows = collect(actingAsSatisfied($owner)->get(route('documents.reports.missing-evidence'))->viewData('page')['props']['rows']);
    expect($rows->firstWhere('link_type', 'ssm_registration')['status'])->toBe('complete');
})->group('M06-04', 'M01-09', '§2.2');

it('inventory is row-filtered; its CSV export is audited and leaks neither storage keys nor secrets', function (): void {
    $sentinel = config('infernix.secret_sentinel');
    $staffA = userWithRoles('STF');
    $mine = storeDocument($staffA, ['title' => "Alpha {$sentinel}"]);
    $theirs = storeDocument(userWithRoles('STF'), ['title' => 'Bravo private']);
    $key = $mine->versions()->firstOrFail()->storage_key;

    $rows = actingAsSatisfied($staffA)->get(route('documents.reports.inventory'))->assertOk()->viewData('page')['props']['rows'];
    expect(collect($rows)->pluck('id')->all())->toBe([$mine->id]);

    actingAsSatisfied($staffA)->get(route('documents.reports.inventory.export'))->assertForbidden(); // STF has no export

    $csv = actingAsSatisfied(ownerUser())->get(route('documents.reports.inventory.export'))->assertOk()->streamedContent();
    expect($csv)->toContain('Bravo private')->not->toContain($key)->not->toContain($sentinel)
        ->and(AuditEvent::query()->where('action', 'export')->where('entity_type', 'document_inventory')->exists())->toBeTrue();
    expect(json_encode(AuditEvent::query()->get()))->not->toContain($sentinel);
})->group('M11-05', 'G-08', 'UAT-08', 'M13-05');

it('recent changes shows document events only for documents the user may view', function (): void {
    $staffA = userWithRoles('STF');
    $doc = storeDocument($staffA);
    actingAsSatisfied($staffA)->post(route('documents.versions.store', $doc), ['file' => Files::pdf('v2.pdf', 'v2')])->assertSessionHasNoErrors();

    $mine = collect(actingAsSatisfied($staffA)->get(route('documents.reports.recent-changes'))->assertOk()->viewData('page')['props']['rows']);
    expect($mine->pluck('action')->all())->toContain('version_upload', 'create');

    $theirs = collect(actingAsSatisfied(userWithRoles('STF'))->get(route('documents.reports.recent-changes'))->assertOk()->viewData('page')['props']['rows']);
    expect($theirs->pluck('document_id')->all())->not->toContain($doc->id);
})->group('M06-03', 'M06-04');

it('sidebar shows Documents to every role with document access and the route enforces it server-side', function (): void {
    $nav = collect(actingAsSatisfied(userWithRoles('STF'))->get('/')->viewData('page')['props']['nav']);
    expect($nav->where('group', 'Documents')->pluck('label')->all())->toContain('Documents');
    actingAsSatisfied(userWithRoles('STF'))->get(route('documents.index'))->assertOk();
})->group('G-04', 'NFR-02');
