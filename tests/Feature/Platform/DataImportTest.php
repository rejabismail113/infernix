<?php

declare(strict_types=1);

use App\Modules\Administration\Services\DataImportService;
use App\Modules\Assets\Models\Asset;
use App\Modules\HumanResources\Models\StaffProfile;
use App\Modules\Procurement\Models\Vendor;
use App\Modules\Projects\Models\Client;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS §9.1 */
beforeEach(fn () => Storage::fake(config('infernix.documents.disk')));

it('§9.1: a dry run validates and reports per row; a single bad row blocks the whole import', function (): void {
    $svc = app(DataImportService::class);
    $csv = "name,registration_no,email,payment_terms_days\nAcme Bhd,201901000001,ap@acme.my,30\nBeta Sdn Bhd,,not-an-email,30\nAcme Bhd,201901000001,x@acme.my,30\n";
    $r = $svc->run('clients', $csv, ownerUser(), true);
    expect($r['rows'])->toBe(3)->and($r['created'])->toBe(0)
        ->and(collect($r['errors'])->pluck('row')->unique()->values()->all())->toBe([3, 4])
        ->and(collect($r['errors'])->pluck('message')->implode(' '))->toContain('email')->toContain('Duplicate of row 2');
    $real = $svc->run('clients', $csv, ownerUser(), false);
    expect($real['created'])->toBe(0)->and(Client::query()->where('name', 'Acme Bhd')->exists())->toBeFalse();

    $ok = $svc->run('clients', "name,registration_no\nAcme Bhd,201901000001\nGamma Training,\n", ownerUser(), false);
    expect($ok['created'])->toBe(2)->and(Client::query()->whereIn('name', ['Acme Bhd', 'Gamma Training'])->count())->toBe(2);
    // Re-importing the same data is refused (already exists).
    expect($svc->run('clients', "name\nAcme Bhd\n", ownerUser(), true)['errors'][0]['message'])->toContain('Already exists');
    expect(DB::table('audit_events')->where('action', 'import')->exists())->toBeTrue();
})->group('§9.1');

it('§9.1: vendors, staff (identifiers encrypted + masked in preview) and assets import through the module services', function (): void {
    $svc = app(DataImportService::class);
    expect($svc->run('vendors', "name,registration_no,bank_reference\nPrint Hub,PH-1,5140 1111 2222\n", ownerUser(), false)['created'])->toBe(1);
    expect((string) DB::table('vendors')->where('name', 'Print Hub')->value('bank_reference'))->not->toContain('2222');

    $staffCsv = "legal_name,engagement_type,classification,id_type,id_number,email,joined_on,rate_type,rate_amount\nSiti Aminah,part_time,contractor,nric,880202-10-1234,siti@example.com,2026-01-05,hourly,60.00\n";
    $dry = $svc->run('staff', $staffCsv, ownerUser(), true);
    expect($dry['errors'])->toBe([])->and($dry['preview'][0]['id_number'])->toBe('**********1234');
    expect($svc->run('staff', $staffCsv, ownerUser(), false)['created'])->toBe(1);
    $s = StaffProfile::query()->where('email', 'siti@example.com')->sole();
    expect($s->staff_no)->toStartWith('ST-')->and($s->maskedId())->toEndWith('1234')->and($s->engagements()->count())->toBe(1);

    $assets = "name,category,serial_no,acquired_on,cost,useful_life_months\nLaptop A,Computers & laptops,SN-A1,2025-03-01,4200.00,36\nDesk,Unknown cat,,2025-03-01,300,\n";
    expect($svc->run('assets', $assets, ownerUser(), true)['errors'][0]['field'])->toBe('category');
    expect($svc->run('assets', "name,category,serial_no,acquired_on,cost\nLaptop A,Computers & laptops,SN-A1,2025-03-01,4200.00\n", ownerUser(), false)['created'])->toBe(1);
    expect(Asset::query()->where('serial_no', 'SN-A1')->sole()->acquisition_type)->toBe('opening_balance')->and(DB::table('journals')->count())->toBe(0);
    expect(Vendor::query()->count())->toBe(1);
})->group('§9.1', 'M04-06', 'M05-01');

it('§9.1: opening balances must balance and post as one journal through PostingService', function (): void {
    $svc = app(DataImportService::class);
    $bad = $svc->run('opening_balances', "account_code,debit,credit\n1010,5000.00,\n3010,,4000.00\n", ownerUser(), true);
    expect(collect($bad['errors'])->pluck('message')->implode(' '))->toContain('do not balance');
    $r = $svc->run('opening_balances', "account_code,debit,credit,description\n1010,5000.00,,Maybank b/f\n3010,,5000.00,Owner capital b/f\n", ownerUser(), false, today()->startOfMonth()->toDateString());
    expect($r['created'])->toBe(2)->and($r['journal_id'])->not->toBeNull()->and(balanceOf('1010'))->toBe('5000.00')->and(balanceOf('3010'))->toBe('-5000.00')
        ->and(DB::table('journals')->where('id', $r['journal_id'])->value('source_type'))->toBe('opening_balance');
    expect($svc->run('opening_balances', "account_code,debit,credit\n9999,1,\n", ownerUser(), true)['errors'][0]['field'])->toBe('account_code');
})->group('§9.1', 'M01-06');

it('§9.1: the CLI and the Owner-only admin page run the same importer', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, "name\nCLI Client\n");
    expect(Artisan::call('import:csv', ['type' => 'clients', 'file' => $path, '--dry-run' => true]))->toBe(0)->and(Client::query()->where('name', 'CLI Client')->exists())->toBeFalse();
    expect(Artisan::call('import:csv', ['type' => 'clients', 'file' => $path]))->toBe(0)->and(Client::query()->where('name', 'CLI Client')->exists())->toBeTrue();
    @unlink($path);
    actingAsSatisfied(userWithRoles('FIN'))->get(route('admin.imports.index'))->assertForbidden();
    actingAsSatisfied(ownerUser())->get(route('admin.imports.template', 'staff'))->assertOk()->assertSee('legal_name,preferred_name');
    actingAsSatisfied(ownerUser())->post(route('admin.imports.run'), ['type' => 'vendors', 'dry_run' => true, 'file' => Files::csv('v.csv', "name\nWeb Vendor\n")])
        ->assertSessionHas('data_import', fn (array $r) => $r['rows'] === 1 && $r['errors'] === [] && $r['dry_run'] === true);
})->group('§9.1');
