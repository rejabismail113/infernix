<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Documents\Contracts\DocumentStore;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\CommercialDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renders an issued document and stores the PDF in the Document Centre as a frozen `issued_document`
 * (never overwritten, M03-07 / M06-03). Runs AFTER the issuing transaction commits — never while the
 * numbering lock is held (CLAUDE.md rule 6). A failure leaves the document issued without a PDF; it can be
 * regenerated from the same snapshot.
 *
 * @see SRS M03-01, M03-04, M03-06, M03-07, M06-03
 */
final class DocumentPdfService
{
    public function __construct(private readonly TemplateRenderer $templates, private readonly PdfRenderer $pdf, private readonly DocumentStore $documents) {}

    public function storeIssued(CommercialDocument $doc, User $actor, ?LinkTarget $link = null): ?int
    {
        $doc->refresh();
        if ($doc->pdf_document_id !== null) {
            return $doc->pdf_document_id;
        }
        try {
            $id = $this->store($doc, $actor, false, $link ?? $this->defaultLink($doc));
            $doc->forceFill(['pdf_document_id' => $id])->save();

            return $id;
        } catch (CommercialRejected $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Issued PDF generation failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** The original PDF is frozen, so voiding stores a separate VOID-watermarked copy of the same snapshot. */
    public function storeVoidCopy(CommercialDocument $doc, User $actor, ?LinkTarget $link = null): ?int
    {
        try {
            $id = $this->store($doc, $actor, true, $link ?? $this->defaultLink($doc));
            $doc->forceFill(['void_pdf_document_id' => $id])->save();

            return $id;
        } catch (Throwable $e) {
            Log::error('VOID copy generation failed', ['document_id' => $doc->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function store(CommercialDocument $doc, User $actor, bool $void, LinkTarget $link): int
    {
        $bytes = $this->pdf->render($this->templates->html($doc, $void), $doc->type === DocumentType::Certificate);
        $name = str_replace(['/', ' '], '-', $doc->displayNo()).($void ? '-VOID' : '').'.pdf';
        $path = tempnam(sys_get_temp_dir(), 'cd');
        file_put_contents($path, $bytes);
        try {
            $stored = $this->documents->attach(new UploadedFile($path, $name, 'application/pdf', null, true), $actor, [
                'title' => $doc->type->label().' '.$doc->displayNo().($void ? ' (VOID copy)' : ''),
                'classification' => 'confidential', 'retention_rule' => 'finance_7y',
            ], $link);
        } finally {
            @unlink($path);
        }

        return $stored->documentId;
    }

    private function defaultLink(CommercialDocument $doc): LinkTarget
    {
        return match ($doc->type) {
            DocumentType::Receipt => new LinkTarget('receipt', (int) $doc->source_id, 'issued_document'),
            DocumentType::PaymentVoucher => $doc->source_type === 'payment_batch'
                ? new LinkTarget('payment_batch', (int) $doc->source_id, 'issued_document')
                : new LinkTarget('payment', (int) $doc->source_id, 'issued_document'),
            default => new LinkTarget($doc->type->value, $doc->id, 'issued_document'),
        };
    }
}
