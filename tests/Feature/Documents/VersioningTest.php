<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Exceptions\DocumentRejected;
use App\Modules\DocumentCentre\Services\DocumentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M06-03, M03-07, G-04 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('re-upload creates a new version; earlier versions stay intact and downloadable', function (): void {
    $owner = ownerUser();
    $doc = storeDocument($owner, [], null, Files::pdf('v1.pdf', 'first-version'));

    actingAsSatisfied($owner)->post(route('documents.versions.store', $doc), ['file' => Files::pdf('v2.pdf', 'second-version')])->assertSessionHasNoErrors();

    $doc->refresh();
    $versions = $doc->versions()->get();
    expect($doc->current_version_no)->toBe(2)
        ->and($versions->pluck('version_no')->all())->toBe([1, 2])
        ->and($versions[0]->storage_key)->not->toBe($versions[1]->storage_key)
        ->and($versions[0]->content_hash)->not->toBe($versions[1]->content_hash)
        ->and(Storage::disk(config('infernix.documents.disk'))->get($versions[0]->storage_key))->toContain('first-version')
        ->and(AuditEvent::query()->where('action', 'version_upload')->where('entity_id', $doc->id)->exists())->toBeTrue();

    $url = (string) actingAsSatisfied($owner)->get(route('documents.download', ['document' => $doc->id, 'version' => 1]))->headers->get('Location');
    expect(actingAsSatisfied($owner)->get($url)->streamedContent())->toContain('first-version');
})->group('M06-03', 'G-04');

it('versions are immutable rows at the database level', function (): void {
    $v = storeDocument(ownerUser())->versions()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('document_versions')->where('id', $v->id)->update(['content_hash' => str_repeat('a', 64)])))
        ->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::transaction(fn () => DB::table('document_versions')->where('id', $v->id)->update(['storage_key' => 'x/y/z'])))
        ->toThrow(QueryException::class, 'immutable');
    expect(fn () => DB::transaction(fn () => DB::table('document_versions')->where('id', $v->id)->delete()))
        ->toThrow(QueryException::class, 'never deleted');
})->group('M06-03', '§6.3');

it('freezes documents linked to an issued document: no new versions via the app or the database', function (): void {
    $owner = ownerUser();
    // Stand-in for M03 commercial documents (Prompt 3): a linkable type that accepts issued_document links.
    app(DocumentLinkRegistry::class)->register(new LinkableEntityType(
        type: 'test_issued', modelClass: User::class, label: 'Issued (test)', linkTypes: ['issued_document'],
    ));
    $doc = storeDocument($owner);
    app(DocumentService::class)->link($doc->id, new LinkTarget('test_issued', $owner->id, 'issued_document'), $owner);

    expect($doc->fresh()->frozen)->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'freeze')->where('entity_id', $doc->id)->exists())->toBeTrue();

    actingAsSatisfied($owner)->post(route('documents.versions.store', $doc), ['file' => Files::pdf()])
        ->assertSessionHasErrors('file');
    expectSessionErrorContains('file', 'frozen');

    $v1 = $doc->versions()->firstOrFail();
    expect(fn () => DB::transaction(fn () => DB::table('document_versions')->insert([
        'document_id' => $doc->id, 'version_no' => 2, 'file_name' => 'x.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 1, 'content_hash' => $v1->content_hash, 'storage_key' => 'sneaky/key', 'scan_status' => 'skipped', 'uploaded_by' => $owner->id,
    ])))->toThrow(QueryException::class, 'frozen');
    expect($doc->fresh()->current_version_no)->toBe(1);
})->group('M06-03', 'M03-07');

it('explicit freeze() via the kernel contract blocks new versions too', function (): void {
    $owner = ownerUser();
    $doc = storeDocument($owner);
    app(DocumentStore::class)->freeze($doc->id, $owner, 'Invoice INV-2026-0001 issued');
    expect(fn () => app(DocumentService::class)->newVersion($doc->fresh(), Files::pdf(), $owner))
        ->toThrow(DocumentRejected::class, 'frozen');
})->group('M06-03', 'M03-07');

it('only users who may edit the document can add versions', function (): void {
    $staff = userWithRoles('STF');
    $doc = storeDocument($staff, [], new LinkTarget('user', $staff->id, 'attachment'));
    actingAsSatisfied(userWithRoles('AUD'))->post(route('documents.versions.store', $doc), ['file' => Files::pdf()])->assertForbidden();
    actingAsSatisfied(userWithRoles('STF'))->post(route('documents.versions.store', $doc), ['file' => Files::pdf()])->assertForbidden();
    actingAsSatisfied($staff)->post(route('documents.versions.store', $doc), ['file' => Files::pdf()])->assertSessionHasNoErrors();
})->group('M06-03', 'NFR-02');
