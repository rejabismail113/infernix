<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
 * G-11 (Prompt 6): projects, clients, staff and client/vendor documents are in the global search index; every
 * hit is re-checked against its record policy, and staff entries never carry identity or bank data.
 */
it('G-11: projects, clients, staff and invoices are searchable, filtered by record access', function (): void {
    $pm = userWithRoles('PM');
    $mine = makeProject($pm);
    $other = makeProject();
    $mine->forceFill(['name' => 'Zebrafish leadership course'])->save();
    $other->forceFill(['name' => 'Zebrafish coding bootcamp'])->save();

    $hits = fn ($user, string $q) => collect(actingAsSatisfied($user)->getJson(route('search', ['q' => $q]))->assertOk()->json('results'));
    expect($hits(ownerUser(), 'Zebrafish')->where('type', 'project')->count())->toBe(2)
        ->and($hits($pm, 'Zebrafish')->where('type', 'project')->pluck('id')->all())->toBe([$mine->id])
        ->and($hits(userWithRoles('STF'), 'Zebrafish')->count())->toBe(0);

    $invoice = issuedInvoice('1200.00', $mine->client()->firstOrFail(), ['project_id' => $mine->id, 'title' => 'Quokka workshop fee']);
    expect($hits(ownerUser(), 'Quokka')->where('type', 'invoice')->pluck('id')->all())->toBe([$invoice->id])
        ->and($hits(userWithRoles('HR'), 'Quokka')->count())->toBe(0);

    $staff = makeStaff(userWithRoles('STF'));
    $staff->forceFill(['legal_name' => 'Wombat Binti Ali'])->save();
    expect($hits(userWithRoles('HR'), 'Wombat')->where('type', 'staff')->pluck('id')->all())->toBe([$staff->id])
        ->and($hits(userWithRoles('STF'), 'Wombat')->count())->toBe(0);
    $row = DB::table('search_index')->where('entity_type', 'staff')->where('entity_id', $staff->id)->first();
    expect(json_encode($row))->toContain('Wombat')->not->toContain('900101')->not->toContain('5678'); // makeStaff NRIC 900101-14-5678
})->group('G-11', 'NFR-02', 'NFR-07');
