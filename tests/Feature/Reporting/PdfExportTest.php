<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\Reporting\Models\Export;
use App\Modules\Reporting\Services\ExportService;

/* Every export is also available as PDF (owner request 2026-11-07): Export Centre, schedules and quick-export links. */

it('Export Centre produces a PDF stored privately in the Document Centre and audited', function (): void {
    $owner = ownerUser();
    $export = app(ExportService::class)->export($owner, 'projects.pipeline', 'pdf', []);
    $doc = Document::query()->findOrFail($export->document_id);
    $version = $doc->versions()->firstOrFail();
    expect($export->format)->toBe('pdf')->and($version->mime_type)->toBe('application/pdf')->and($version->file_name)->toEndWith('.pdf')
        ->and(AuditEvent::query()->where('action', 'export')->latest('id')->firstOrFail()->after['format'] ?? null)->toBe('pdf');

    actingAsSatisfied($owner)->post(route('reports.exports.store'), ['report' => 'projects.pipeline', 'format' => 'pdf'])->assertRedirectContains('/documents/');
    expect(Export::query()->where('format', 'pdf')->count())->toBe(2);
})->group('M11-05');

it('any quick-export link returns a PDF with ?format=pdf, still permission-checked and audited', function (): void {
    $res = actingAsSatisfied(ownerUser())->get(route('admin.audit-log.export', ['format' => 'pdf']))->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($res->headers->get('Content-Disposition'))->toContain('.pdf')
        ->and(substr($res->streamedContent(), 0, 4))->toBe('%PDF');
    expect(AuditEvent::query()->where('action', 'export')->latest('id')->firstOrFail()->after['format'] ?? null)->toBe('pdf');

    actingAsSatisfied(userWithRoles('STF'))->get(route('admin.audit-log.export', ['format' => 'pdf']))->assertForbidden();
    actingAsSatisfied(ownerUser())->get(route('admin.audit-log.export'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
})->group('M11-05', 'G-08', 'NFR-02');

it('scheduled report emails may use PDF', function (): void {
    actingAsSatisfied(ownerUser())->post(route('reports.schedules.store'), ['report' => 'projects.pipeline', 'format' => 'pdf', 'frequency' => 'weekly', 'day' => 1, 'recipient_ids' => [ownerUser()->id]])
        ->assertSessionHasNoErrors();
})->group('M11-06');
