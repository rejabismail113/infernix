<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentSearch;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS M06-05, G-10, G-06 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('hard delete requires OWN + a reason, removes the objects, keeps a tombstone and is audited', function (): void {
    $owner = ownerUser();
    $doc = storeDocument($owner, ['retention_rule' => 'none']);
    $key = $doc->versions()->firstOrFail()->storage_key;

    actingAsSatisfied(userWithRoles('FIN'))->delete(route('documents.destroy', $doc), ['reason' => 'cleanup please'])->assertForbidden();
    actingAsSatisfied(userWithRoles('HR'))->delete(route('documents.destroy', $doc), ['reason' => 'cleanup please'])->assertForbidden();
    actingAsSatisfied($owner)->delete(route('documents.destroy', $doc), ['reason' => ''])->assertSessionHasErrors('reason');
    expect(Storage::disk(config('infernix.documents.disk'))->exists($key))->toBeTrue();

    actingAsSatisfied($owner)->delete(route('documents.destroy', $doc), ['reason' => 'Duplicate upload of the same receipt'])
        ->assertRedirect(route('documents.index'));

    $doc->refresh();
    expect($doc->isHardDeleted())->toBeTrue()
        ->and($doc->hard_delete_reason)->toBe('Duplicate upload of the same receipt')
        ->and(Storage::disk(config('infernix.documents.disk'))->exists($key))->toBeFalse()
        ->and($doc->versions()->count())->toBe(1) // tombstone metadata kept
        ->and(AuditEvent::query()->where('action', 'hard_delete')->where('entity_id', $doc->id)->where('reason', 'Duplicate upload of the same receipt')->exists())->toBeTrue()
        ->and(app(DocumentSearch::class)->query($owner, ['include_archived' => true])->pluck('id')->all())->not->toContain($doc->id);
})->group('M06-05', 'G-06', 'G-10');

it('hard delete requires recent re-authentication', function (): void {
    $owner = ownerUser();
    $doc = storeDocument($owner, ['retention_rule' => 'none']);
    actingAsSatisfied($owner);
    $this->flushSession();
    $this->actingAs($owner)->withSession([EnsureMfa::SESSION_KEY => now()->toIso8601String()])
        ->delete(route('documents.destroy', $doc), ['reason' => 'Uploaded twice'])->assertRedirectContains('/reauth');
    expect($doc->fresh()->isHardDeleted())->toBeFalse();
})->group('M06-05', '§2');

it('refuses hard delete under legal hold, inside retention, or for frozen documents', function (): void {
    $owner = ownerUser();
    $held = storeDocument($owner, ['retention_rule' => 'none']);
    actingAsSatisfied(userWithRoles('FIN'))->post(route('documents.legal-hold', $held), ['hold' => true, 'reason' => 'x'])->assertForbidden();
    actingAsSatisfied($owner)->post(route('documents.legal-hold', $held), ['hold' => true, 'reason' => 'LHDN audit 2026'])->assertSessionHasNoErrors();
    expect(AuditEvent::query()->where('action', 'legal_hold_on')->where('entity_id', $held->id)->exists())->toBeTrue();
    actingAsSatisfied($owner)->delete(route('documents.destroy', $held), ['reason' => 'Try to delete'])->assertSessionHasErrors('reason');
    expectSessionErrorContains('reason', 'legal hold');

    $retained = storeDocument($owner); // default retention: general_7y
    expect($retained->retention_until?->toDateString())->toBe(today()->addMonths(84)->toDateString());
    actingAsSatisfied($owner)->delete(route('documents.destroy', $retained), ['reason' => 'Try to delete'])->assertSessionHasErrors('reason');
    expectSessionErrorContains('reason', 'retention period');

    $frozen = storeDocument($owner, ['retention_rule' => 'none']);
    app(DocumentStore::class)->freeze($frozen->id, $owner, 'issued');
    actingAsSatisfied($owner)->delete(route('documents.destroy', $frozen), ['reason' => 'Try to delete'])->assertSessionHasErrors('reason');
    expectSessionErrorContains('reason', 'frozen');

    expect(Document::query()->whereNotNull('hard_deleted_at')->count())->toBe(0);
})->group('M06-05', 'M03-07');

it('soft-archives: hidden from default search, still viewable and restorable', function (): void {
    $staff = userWithRoles('STF');
    $doc = storeDocument($staff, [], new LinkTarget('user', $staff->id, 'attachment'));
    actingAsSatisfied($staff)->post(route('documents.archive', $doc), ['reason' => 'Superseded'])->assertSessionHasNoErrors();

    $search = app(DocumentSearch::class);
    expect($doc->fresh()->isArchived())->toBeTrue()
        ->and($search->query($staff)->pluck('id')->all())->not->toContain($doc->id)
        ->and($search->query($staff, ['include_archived' => true])->pluck('id')->all())->toContain($doc->id);
    actingAsSatisfied($staff)->get(route('documents.download', $doc))->assertRedirect();

    actingAsSatisfied($staff)->post(route('documents.archive', $doc), ['restore' => true])->assertSessionHasNoErrors();
    expect($doc->fresh()->isArchived())->toBeFalse()
        ->and(AuditEvent::query()->whereIn('action', ['archive', 'unarchive'])->where('entity_id', $doc->id)->count())->toBe(2);
})->group('G-10', 'M06-05');

it('explicit shares grant view (even across classification) and expire', function (): void {
    $hr = userWithRoles('HR');
    $fin = userWithRoles('FIN');
    $doc = storeDocument($hr, ['classification' => 'medical']);

    actingAsSatisfied($fin)->get(route('documents.show', $doc))->assertForbidden();
    actingAsSatisfied($hr)->post(route('documents.shares.store', $doc), ['user_id' => $fin->id, 'reason' => 'Reimbursement check', 'expires_at' => now()->addDay()->toDateTimeString()])->assertSessionHasNoErrors();
    actingAsSatisfied($fin)->get(route('documents.show', $doc))->assertOk();
    // Shares never grant edit.
    actingAsSatisfied($fin)->post(route('documents.versions.store', $doc), ['file' => Files::pdf()])->assertForbidden();

    $this->travel(2)->days();
    actingAsSatisfied($fin)->get(route('documents.show', $doc))->assertForbidden();
    expect(AuditEvent::query()->where('action', 'share')->where('entity_id', $doc->id)->exists())->toBeTrue();
})->group('M02-07', '§2.1', 'NFR-07');

it('links only to registered entity types that the user may update', function (): void {
    $staff = userWithRoles('STF');
    $other = userWithRoles('STF');
    $doc = storeDocument($staff);

    actingAsSatisfied($staff)->post(route('documents.links.store', $doc), ['entity_type' => 'App\\Kernel\\Identity\\Models\\User', 'entity_id' => $staff->id, 'link_type' => 'attachment'])->assertSessionHasErrors('entity_type');
    actingAsSatisfied($staff)->post(route('documents.links.store', $doc), ['entity_type' => 'invoice', 'entity_id' => 1, 'link_type' => 'attachment'])->assertSessionHasErrors('entity_type');
    actingAsSatisfied($staff)->post(route('documents.links.store', $doc), ['entity_type' => 'user', 'entity_id' => $other->id, 'link_type' => 'attachment'])->assertForbidden();
    actingAsSatisfied($staff)->post(route('documents.links.store', $doc), ['entity_type' => 'user', 'entity_id' => $staff->id, 'link_type' => 'ssm_registration'])->assertSessionHasErrors('entity_id');
    actingAsSatisfied($staff)->post(route('documents.links.store', $doc), ['entity_type' => 'user', 'entity_id' => $staff->id, 'link_type' => 'contract'])->assertSessionHasNoErrors();

    $link = $doc->links()->firstOrFail();
    expect($link->entity_type)->toBe('user')->and($link->entity)->toBeInstanceOf(User::class);
})->group('M06-01', '§6.1');
