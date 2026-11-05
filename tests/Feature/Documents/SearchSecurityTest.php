<?php

declare(strict_types=1);

use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\Administration\Models\Company;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\DocumentShare;
use App\Modules\DocumentCentre\Services\DocumentSearch;
use App\Modules\DocumentCentre\Services\DocumentService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Search must never return a row the user cannot view, and must not hide rows they can view:
 * the SQL filter and the record-level policy are checked against each other on a mixed data set.
 *
 * @see SRS M06-04, G-11, NFR-02, §2.1
 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $svc = app(DocumentService::class);
    $company = Company::current();
    $u = [
        'own' => ownerUser(), 'fin' => userWithRoles('FIN'), 'hr' => userWithRoles('HR'), 'pm' => userWithRoles('PM'),
        'stfA' => userWithRoles('STF'), 'stfB' => userWithRoles('STF'), 'aud' => userWithRoles('AUD'), 'app' => userWithRoles('APP'),
    ];
    $d = [];
    $d['stfA_unlinked'] = storeDocument($u['stfA'], ['title' => 'Alpha notes']);
    $d['stfA_folder'] = storeDocument($u['stfA'], ['title' => 'Alpha NDA', 'tags' => ['nda']], new LinkTarget('user', $u['stfA']->id, 'nda'));
    $d['stfB_medical'] = storeDocument($u['stfB'], ['title' => 'Bravo clinic', 'classification' => 'medical'], new LinkTarget('user', $u['stfB']->id, 'evidence'));
    $d['hr_restricted'] = storeDocument($u['hr'], ['title' => 'Payroll review', 'classification' => 'restricted']);
    $d['fin_company'] = storeDocument($u['fin'], ['title' => 'Bank letter'], new LinkTarget('company', $company->id, 'contract'));
    $d['own_company_restricted'] = storeDocument($u['own'], ['title' => 'SSM certificate', 'classification' => 'restricted'], new LinkTarget('company', $company->id, 'ssm_registration'));
    $d['pm_public_shared'] = storeDocument($u['pm'], ['title' => 'Kickoff deck', 'classification' => 'public_internal']);
    $svc->share($d['pm_public_shared'], $u['stfA'], null, 'project member', $u['pm']);
    $d['hr_medical_shared_fin'] = storeDocument($u['hr'], ['title' => 'Medical cert', 'classification' => 'medical'], new LinkTarget('user', $u['stfB']->id, 'certificate'));
    $svc->share($d['hr_medical_shared_fin'], $u['fin'], null, 'reimbursement', $u['hr']);
    $d['own_medical'] = storeDocument($u['own'], ['title' => 'Owner medical', 'classification' => 'medical']);
    $d['stfA_archived'] = storeDocument($u['stfA'], ['title' => 'Old alpha']);
    $svc->archive($d['stfA_archived'], $u['stfA']);
    $d['hard_deleted'] = storeDocument($u['own'], ['title' => 'Gone', 'retention_rule' => 'none']);
    $svc->hardDelete($d['hard_deleted'], $u['own'], 'mistake upload');
    $d['pm_expired_share'] = storeDocument($u['pm'], ['title' => 'Expired share']);
    $share = $svc->share($d['pm_expired_share'], $u['stfB'], now()->addHour(), 'temp', $u['pm']);
    DocumentShare::query()->whereKey($share->id)->update(['expires_at' => now()->subHour()]);

    $this->u = $u;
    $this->d = $d;
});

it('returns exactly the documents the policy allows, for every role', function (): void {
    $all = Document::query()->get();
    foreach ($this->u as $name => $user) {
        /** @var User $user */
        $user = $user->fresh(['roles']);
        $found = app(DocumentSearch::class)->query($user, ['include_archived' => true])->pluck('id')->sort()->values()->all();
        $allowed = $all->filter(fn (Document $doc): bool => Gate::forUser($user)->allows('view', $doc))->pluck('id')->sort()->values()->all();
        expect($found)->toBe($allowed, "search vs policy mismatch for {$name}");
    }
})->group('M06-04', 'G-11', 'NFR-02');

it('applies classification, inheritance, shares and expiry as specified', function (): void {
    $ids = fn (string $who): array => app(DocumentSearch::class)->query($this->u[$who]->fresh(['roles']), ['include_archived' => true])->pluck('id')->all();
    $d = fn (string $k): int => $this->d[$k]->id;

    // STF: own files + explicit shares only; never others' folders; expired shares give nothing.
    expect($ids('stfA'))->toEqualCanonicalizing([$d('stfA_unlinked'), $d('stfA_folder'), $d('pm_public_shared'), $d('stfA_archived')]);
    expect($ids('stfB'))->toEqualCanonicalizing([$d('stfB_medical')]);
    // FIN: finance/company-linked + explicit medical share; never other medical files.
    expect($ids('fin'))->toContain($d('fin_company'), $d('own_company_restricted'), $d('hr_medical_shared_fin'))
        ->not->toContain($d('stfB_medical'), $d('own_medical'), $d('stfA_folder'), $d('hr_restricted'));
    // HR: staff folders (incl. medical), own restricted, company docs.
    expect($ids('hr'))->toContain($d('stfA_folder'), $d('stfB_medical'), $d('hr_restricted'), $d('hr_medical_shared_fin'), $d('fin_company'))
        ->not->toContain($d('own_medical'), $d('stfA_unlinked'));
    // AUD: company + staff-linked non-medical; medical never.
    expect($ids('aud'))->toContain($d('fin_company'), $d('own_company_restricted'), $d('stfA_folder'))
        ->not->toContain($d('stfB_medical'), $d('hr_medical_shared_fin'), $d('own_medical'));
    // APP: nothing that is not delegated/shared.
    expect($ids('app'))->toBe([]);
    // OWN: everything except the hard-deleted tombstone.
    expect($ids('own'))->toHaveCount(count($this->d) - 1)->not->toContain($d('hard_deleted'));
})->group('M06-04', '§2.1', 'NFR-07', 'M02-07');

it('filters by text, tag, classification and link without widening visibility', function (): void {
    $search = app(DocumentSearch::class);
    $stfB = $this->u['stfB']->fresh(['roles']);
    expect($search->query($stfB, ['q' => 'Alpha'])->count())->toBe(0)
        ->and($search->query($this->u['stfA'], ['q' => 'alpha'])->pluck('id')->all())->toEqualCanonicalizing([$this->d['stfA_unlinked']->id, $this->d['stfA_folder']->id])
        ->and($search->query($this->u['stfA'], ['q' => 'receipt.pdf'])->count())->toBe(3)
        ->and($search->query($this->u['hr'], ['tag' => 'nda'])->pluck('id')->all())->toBe([$this->d['stfA_folder']->id])
        ->and($search->query($this->u['own'], ['classification' => 'medical'])->count())->toBe(3)
        ->and($search->query($this->u['fin'], ['classification' => 'medical'])->pluck('id')->all())->toBe([$this->d['hr_medical_shared_fin']->id])
        ->and($search->query($this->u['own'], ['entity_type' => 'company'])->count())->toBe(2)
        ->and($search->query($this->u['own'], ['entity_type' => 'company', 'link_type' => 'ssm_registration'])->pluck('id')->all())->toBe([$this->d['own_company_restricted']->id]);
})->group('M06-04', 'G-04');

it('the index page only lists visible rows and its total count does not leak hidden rows', function (): void {
    $res = actingAsSatisfied($this->u['stfB'])->get(route('documents.index', ['q' => 'a']))->assertOk();
    $props = $res->viewData('page')['props'];
    expect(collect($props['documents']['data'])->pluck('id')->all())->toBe([$this->d['stfB_medical']->id])
        ->and($props['documents']['total'])->toBe(1);
    expect(json_encode($props))->not->toContain('Alpha')->not->toContain('Payroll')->not->toContain('SSM certificate');
})->group('M06-04', 'G-11', 'UAT-01');
