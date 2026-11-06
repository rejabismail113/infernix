<?php

declare(strict_types=1);

use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Services\CommercialDocumentService;
use App\Modules\Commercial\Services\GeneratorService;
use App\Modules\HumanResources\Models\StaffMemo;
use App\Modules\Procurement\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** @see SRS M03-05, M03-06, M03-07, M04 memo inbox */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('M03-06: a staff memo is numbered, frozen, delivered to the inbox and visible only to its recipient', function (): void {
    $staffUser = userWithRoles('STF');
    $staff = makeStaff($staffUser);
    $hr = userWithRoles('HR');
    $gen = app(GeneratorService::class);
    $memo = $gen->createDraft(DocumentType::Memo, ['staff_profile_id' => $staff->id, 'subject' => 'Training schedule', 'body' => "Please note the March schedule.\nThank you."], $hr);
    $memo = $gen->issue($memo, $hr);
    expect($memo->document_no)->toMatch('/^MEMO-\d{4}-0001$/')->and($memo->status)->toBe(DocumentStatus::Issued)->and($memo->pdf_document_id)->not->toBeNull()
        ->and(StaffMemo::query()->where('staff_profile_id', $staff->id)->where('commercial_document_id', $memo->id)->exists())->toBeTrue();
    expect(fn () => DB::transaction(fn () => DB::table('commercial_documents')->where('id', $memo->id)->update(['body' => 'changed'])))->toThrow(QueryException::class, 'immutable');

    actingAsSatisfied($staffUser)->get(route('hr.memos.index'))->assertOk()->assertSee('Training schedule');
    actingAsSatisfied($staffUser)->get(route('documents.download', ['document' => $memo->pdf_document_id]))->assertRedirect();
    actingAsSatisfied(userWithRoles('STF'))->get(route('documents.download', ['document' => $memo->pdf_document_id]))->assertForbidden();
})->group('M03-06', 'M04-01', 'M03-07');

it('appointment letters default their wording from the rate agreement in force', function (): void {
    $staff = makeStaff(null, 'contractor', '75.00');
    $gen = app(GeneratorService::class);
    $letter = $gen->createDraft(DocumentType::AppointmentLetter, ['staff_profile_id' => $staff->id, 'subject' => 'Appointment as trainer', 'effective_date' => '2026-02-01'], ownerUser());
    expect($letter->body)->toContain('RM 75.00 per hour')->toContain('version 1')->toContain('1 February 2026');
    $issued = $gen->issue($letter, ownerUser());
    expect($issued->document_no)->toStartWith('APL-');
    $plain = $gen->createDraft(DocumentType::Letter, ['recipient_name' => 'Pengarah, Jabatan Contoh', 'subject' => 'Letter of support', 'body' => 'We confirm…'], ownerUser());
    expect($gen->issue($plain, ownerUser())->document_no)->toStartWith('LTR-');
})->group('M03-06');

it('M03-05: vendor POs are a distinct outgoing type with approval and their own PO numbers; no ledger posting', function (): void {
    $owner = ownerUser();
    $vendor = Vendor::query()->create(['company_id' => $owner->company_id, 'name' => 'Laptop Supplier', 'status' => 'active']);
    $fin = userWithRoles('FIN');
    $svc = app(CommercialDocumentService::class);
    $po = $svc->createDraft(DocumentType::VendorPo, ['vendor_id' => $vendor->id, 'title' => 'Laptops', 'lines' => [['description' => 'Laptop', 'quantity' => '2', 'unit_price' => '3500']]], $fin);
    expect($po->counterparty_type)->toBe('vendor')->and($po->client_id)->toBeNull();
    $svc->submit($po, $fin);
    approveFully($po);
    $po = $svc->issue($po->fresh(), $fin);
    expect($po->document_no)->toMatch('/^PO-\d{4}-0001$/')->and($po->journal_id)->toBeNull()->and($po->counterparty_snapshot['name'])->toBe('Laptop Supplier')
        ->and(DB::table('journals')->count())->toBe(0);
})->group('M03-05');
