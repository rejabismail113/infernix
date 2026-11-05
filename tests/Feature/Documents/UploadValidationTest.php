<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Kernel\Documents\Contracts\DocumentScanner;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Documents\ScanResult;
use App\Kernel\Documents\ScanStatus;
use App\Modules\DocumentCentre\Exceptions\DocumentRejected;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use App\Modules\DocumentCentre\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Files;

/** @see SRS G-07, M06-03, §6.3 */
beforeEach(function (): void {
    Storage::fake(config('infernix.documents.disk'));
});

it('accepts every allowed type by content', function (UploadedFile $file, string $mime): void {
    actingAsSatisfied(ownerUser())->post(route('documents.store'), ['file' => $file, 'classification' => 'confidential'])->assertSessionHasNoErrors()->assertRedirect();
    expect(DocumentVersion::query()->latest('id')->first()?->mime_type)->toBe($mime);
})->with([
    'pdf' => fn () => [Files::pdf(), 'application/pdf'],
    'png' => fn () => [Files::png(), 'image/png'],
    'jpg' => fn () => [Files::jpg(), 'image/jpeg'],
    'csv' => fn () => [Files::csv(), 'text/csv'],
    'xlsx' => fn () => [Files::xlsx(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'docx' => fn () => [Files::docx(), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
])->group('G-07', 'M06-03');

it('rejects disallowed or disguised files and stores nothing', function (UploadedFile $file, string $message): void {
    actingAsSatisfied(ownerUser())->post(route('documents.store'), ['file' => $file, 'classification' => 'confidential'])
        ->assertSessionHasErrors('file');
    expectSessionErrorContains('file', $message);
    expect(Document::query()->count())->toBe(0)
        ->and(Storage::disk(config('infernix.documents.disk'))->allFiles())->toBe([])
        ->and(AuditEvent::query()->where('action', 'upload_rejected')->exists())->toBeTrue();
})->with([
    'windows exe renamed to pdf' => fn () => [Files::raw('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 64)), 'does not match'],
    'php script renamed to pdf' => fn () => [Files::raw('invoice.pdf', "<?php system(\$_GET['c']); ?>"), 'does not match'],
    'html renamed to png' => fn () => [Files::raw('photo.png', '<html><script>alert(1)</script></html>'), 'does not match'],
    'plain text extension' => fn () => [Files::raw('notes.txt', 'hello'), 'not allowed'],
    'svg (script-capable)' => fn () => [Files::raw('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'not allowed'],
    'php extension' => fn () => [Files::raw('shell.php', '<?php echo 1;'), 'not allowed'],
    'spreadsheet renamed to docx' => fn () => [Files::ooxml('contract.docx', 'xl/'), 'does not match'],
    'bare zip renamed to docx' => fn () => [Files::ooxml('contract.docx', 'other/'), 'not a valid Office'],
    'docx without content types' => fn () => [Files::ooxml('contract.docx', 'word/', [], false), 'not a valid Office'],
    'macro-enabled docx' => fn () => [Files::ooxml('contract.docx', 'word/', ['word/vbaProject.bin' => 'x']), 'Macro-enabled'],
    'binary renamed to csv' => fn () => [Files::raw('list.csv', "a,b\n\x00\x01\x02\x03"), 'does not match'],
    'empty file' => fn () => [Files::raw('empty.pdf', ''), 'empty'],
])->group('G-07', 'M06-03');

it('rejects files over the configured size limit', function (): void {
    config(['infernix.documents.max_size_kb' => 1]);
    actingAsSatisfied(ownerUser())->post(route('documents.store'), ['file' => Files::pdf('big.pdf', str_repeat('A', 2048)), 'classification' => 'confidential'])
        ->assertSessionHasErrors('file');
    expectSessionErrorContains('file', 'exceeds the maximum size');
    expect(Document::query()->count())->toBe(0);
})->group('G-07');

it('records the SHA-256 content hash, size and sniffed MIME, and stores the exact bytes', function (): void {
    $file = Files::pdf('hash-me.pdf', 'integrity');
    $bytes = (string) file_get_contents((string) $file->getRealPath());
    $doc = storeDocument(ownerUser(), [], null, $file);
    $v = $doc->versions()->firstOrFail();

    expect($v->content_hash)->toBe(hash('sha256', $bytes))
        ->and($v->size_bytes)->toBe(strlen($bytes))
        ->and($v->mime_type)->toBe('application/pdf')
        ->and($v->storage_key)->toMatch('#^\d+/unlinked/0/[0-9a-f-]{36}$#')
        ->and(Storage::disk(config('infernix.documents.disk'))->get($v->storage_key))->toBe($bytes);
})->group('M06-03', '§6.3', 'G-07');

it('uses {company}/{entity_type}/{entity_id}/{uuid} keys for linked uploads', function (): void {
    $staff = userWithRoles('STF');
    $doc = storeDocument($staff, [], new LinkTarget('user', $staff->id, 'attachment'));
    expect($doc->versions()->firstOrFail()->storage_key)->toMatch('#^'.$staff->company_id.'/user/'.$staff->id.'/[0-9a-f-]{36}$#');
})->group('M06-01');

it('rejects infected files and files the scanner could not check (fails closed)', function (ScanStatus $status): void {
    app()->instance(DocumentScanner::class, new class($status) implements DocumentScanner
    {
        public function __construct(private ScanStatus $status) {}

        public function scan(string $absolutePath): ScanResult
        {
            return new ScanResult($this->status, 'fake-av');
        }
    });
    actingAsSatisfied(ownerUser())->post(route('documents.store'), ['file' => Files::pdf(), 'classification' => 'confidential'])->assertSessionHasErrors('file');
    expect(Document::query()->count())->toBe(0)->and(Storage::disk(config('infernix.documents.disk'))->allFiles())->toBe([]);
})->with([ScanStatus::Infected, ScanStatus::Error])->group('G-07');

it('removes the stored object if the metadata transaction fails', function (): void {
    expect(fn () => app(DocumentService::class)->attach(Files::pdf(), ownerUser(), ['classification' => 'not-a-class']))
        ->toThrow(DocumentRejected::class);
    expect(Storage::disk(config('infernix.documents.disk'))->allFiles())->toBe([]);
})->group('§6.3');
