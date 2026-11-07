<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\LinkTarget;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Services\DocumentService;
use App\Modules\DocumentCentre\Services\DocumentUrlSigner;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/**
 * UAT-10: a document cannot be fetched without authorization — not by raw storage key, not by a copied
 * direct path, not by someone else's signed link, not after expiry.
 *
 * @see SRS UAT-10, M06-02, NFR-02
 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
    $this->staffA = userWithRoles('STF');
    $this->staffB = userWithRoles('STF');
    $this->doc = storeDocument($this->staffA, ['title' => 'My medical receipt'], new LinkTarget('user', $this->staffA->id, 'attachment'), Files::pdf('receipt.pdf', 'secret-bytes'));
    $this->key = $this->doc->versions()->firstOrFail()->storage_key;
});

it('UAT-10: the raw storage key or a direct path cannot be fetched by anyone', function (): void {
    expect(Storage::disk(config('infernix.documents.disk'))->exists($this->key))->toBeTrue();
    expect(str_starts_with(Storage::disk(config('infernix.documents.disk'))->path($this->key), public_path()))->toBeFalse();

    foreach ([null, $this->staffA, ownerUser()] as $who) {
        $client = $who ? actingAsSatisfied($who) : $this;
        $client->get('/storage/'.$this->key)->assertNotFound();
        $client->get('/'.$this->key)->assertNotFound();
        $client->get('/documents/'.$this->key)->assertNotFound();
        $client->get('/storage/app/private/documents/'.$this->key)->assertNotFound();
    }
})->group('UAT-10', 'M06-02', 'NFR-02');

it('UAT-10: the storage key is never sent to the browser', function (): void {
    $show = actingAsSatisfied($this->staffA)->get(route('documents.show', $this->doc))->assertOk();
    $index = actingAsSatisfied($this->staffA)->get(route('documents.index'))->assertOk();
    foreach ([$show, $index] as $res) {
        $payload = json_encode($res->viewData('page'));
        expect($payload)->not->toContain($this->key)->not->toContain('storage_key');
    }
    expect(json_encode($this->doc->versions()->first()))->not->toContain($this->key);
})->group('UAT-10', 'M06-02');

it('UAT-10: an unauthorized download returns 403 and NO signed URL is generated', function (): void {
    $signer = $this->mock(DocumentUrlSigner::class);
    $signer->shouldNotReceive('temporaryUrl');

    $this->get(route('documents.download', $this->doc))->assertRedirect(route('login'));

    actingAsSatisfied($this->staffB)->get(route('documents.download', $this->doc))
        ->assertForbidden()->assertHeaderMissing('Location');
    actingAsSatisfied(userWithRoles('PM'))->get(route('documents.download', $this->doc))->assertForbidden();
    actingAsSatisfied(userWithRoles('APP'))->get(route('documents.download', $this->doc))->assertForbidden();
    actingAsSatisfied($this->staffB)->get(route('documents.show', $this->doc))->assertForbidden();

    expect(AuditEvent::query()->where('action', 'download')->exists())->toBeFalse();
})->group('UAT-10', 'UAT-01', 'M06-02', 'NFR-02');

it('UAT-10: an authorized download is audited and redirects to a 5-minute, user-bound signed URL', function (): void {
    $res = actingAsSatisfied($this->staffA)->get(route('documents.download', $this->doc))->assertRedirect();
    $url = (string) $res->headers->get('Location');
    expect($url)->toContain('/documents/stream/')->toContain('signature=')->toContain('expires=');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    expect((int) $q['expires'])->toBeLessThanOrEqual(now()->addMinutes(5)->timestamp)
        ->and((int) $q['expires'])->toBeGreaterThan(now()->addMinutes(4)->timestamp)
        ->and(AuditEvent::query()->where('action', 'download')->where('entity_id', $this->doc->id)->where('actor_id', $this->staffA->id)->exists())->toBeTrue();

    $file = actingAsSatisfied($this->staffA)->get($url)->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'application/pdf');
    expect($file->headers->get('Cache-Control'))->toContain('no-store')
        ->and($file->streamedContent())->toContain('secret-bytes');

    // Someone else replaying the link → 403 even though the signature is valid.
    actingAsSatisfied($this->staffB)->get($url)->assertForbidden();
    // Tampered signature / swapped version → 403.
    actingAsSatisfied($this->staffA)->get(str_replace('signature=', 'signature=00', $url))->assertForbidden();
    actingAsSatisfied($this->staffA)->get(preg_replace('#/stream/\d+#', '/stream/999999', $url))->assertForbidden();
    // Guest with the link → login.
    $this->post('/logout');
    $this->flushSession();
    auth()->logout();
    $this->get($url)->assertRedirect(route('login'));
    // Expired after 5 minutes.
    $this->travel(6)->minutes();
    actingAsSatisfied($this->staffA)->get($url)->assertForbidden();
})->group('UAT-10', 'M06-02', 'G-06');

it('inherits access from the linked record: HR (may view the staff record) can download, PM cannot', function (): void {
    actingAsSatisfied(userWithRoles('HR'))->get(route('documents.download', $this->doc))->assertRedirect();
    actingAsSatisfied(userWithRoles('PM'))->get(route('documents.download', $this->doc))->assertForbidden();
})->group('M06-02', '§6.3', 'M04-01');

it('streams through the signed app route by default even on S3, so browsers never need the object-store host', function (): void {
    config(['filesystems.disks.'.config('infernix.documents.disk').'.driver' => 's3']);
    Storage::disk(config('infernix.documents.disk'))->buildTemporaryUrlsUsing(fn (): string => 'http://minio:9000/should-not-be-used');

    $res = actingAsSatisfied($this->staffA)->get(route('documents.download', $this->doc))->assertRedirect();
    $location = (string) $res->headers->get('Location');
    expect($location)->toStartWith(route('documents.stream', ['version' => $this->doc->versions()->first()->id]))
        ->and($location)->toContain('signature=')->not->toContain('minio');
})->group('UAT-10', 'M06-02');

it('uses a presigned object URL with 5-minute expiry when DOCUMENT_DELIVERY=presigned (S3)', function (): void {
    config(['filesystems.disks.'.config('infernix.documents.disk').'.driver' => 's3', 'infernix.documents.delivery' => 'presigned']);
    $captured = [];
    Storage::disk(config('infernix.documents.disk'))->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use (&$captured): string {
        $captured = compact('path', 'expiration', 'options');

        return 'https://minio.test/'.$path.'?X-Amz-Expires=300';
    });

    $res = actingAsSatisfied($this->staffA)->get(route('documents.download', $this->doc))->assertRedirect();
    expect((string) $res->headers->get('Location'))->toStartWith('https://minio.test/')
        ->and($captured['path'])->toBe($this->key)
        ->and($captured['expiration']->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(5)->timestamp)
        ->and($captured['options']['ResponseContentDisposition'])->toStartWith('attachment;');
})->group('UAT-10', 'M06-02');

it('refuses downloads of hard-deleted documents', function (): void {
    $doc = storeDocument(ownerUser(), ['retention_rule' => 'none']);
    app(DocumentService::class)->hardDelete($doc, ownerUser(), 'Uploaded by mistake');
    actingAsSatisfied(ownerUser())->get(route('documents.download', $doc))->assertForbidden();
    actingAsSatisfied(ownerUser())->get(route('documents.show', $doc))->assertForbidden();
    expect(Document::query()->find($doc->id)?->isHardDeleted())->toBeTrue();
})->group('M06-05', 'UAT-10');
