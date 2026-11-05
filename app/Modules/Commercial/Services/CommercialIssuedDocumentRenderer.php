<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Documents\Contracts\IssuedDocumentRenderer;
use App\Kernel\Documents\IssuedDocumentRequest;
use App\Kernel\Money\Money;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\CommercialDocument;

/**
 * Registers an externally-numbered document (payment voucher from M01) in the document register with a full
 * render snapshot and stores its frozen PDF. Idempotent per source record.
 *
 * @see SRS M03-06, M03-07, M03-08
 */
final class CommercialIssuedDocumentRenderer implements IssuedDocumentRenderer
{
    public function __construct(private readonly IssuerProfile $issuer, private readonly TemplateRenderer $templates, private readonly DocumentPdfService $pdfs) {}

    public function issue(IssuedDocumentRequest $request): int
    {
        $type = DocumentType::from($request->type);
        if ($type->isBuilt()) {
            throw new CommercialRejected("{$type->label()} documents are issued through the document builder.");
        }
        $class = DocumentRegistry::modelFor($type);
        /** @var CommercialDocument|null $doc */
        $doc = $class::query()->where('source_type', $request->sourceType)->where('source_id', $request->sourceId)->first();
        if ($doc === null) {
            $doc = new $class;
            $doc->forceFill([
                'company_id' => $request->actor->company_id, 'document_no' => $request->documentNo, 'revision_no' => 0, 'status' => DocumentStatus::Issued,
                'template_version_id' => $this->templates->currentVersion($type)->id, 'issuer_profile_snapshot' => $this->issuer->snapshot(),
                'counterparty_type' => $request->counterpartyType, 'counterparty_snapshot' => $request->counterparty,
                'lines_snapshot' => ['lines' => $request->lines, 'extra' => $request->extra],
                'subtotal' => Money::myr($request->total), 'tax_total' => Money::zero(), 'total' => Money::myr($request->total),
                'issue_date' => $request->issueDate->toDateString(), 'source_type' => $request->sourceType, 'source_id' => $request->sourceId,
                'created_by' => $request->actor->id, 'issued_by' => $request->actor->id, 'issued_at' => now(),
            ])->save();
        }
        $id = $this->pdfs->storeIssued($doc, $request->actor, $request->link);
        if ($id === null) {
            throw new CommercialRejected("The PDF for {$request->documentNo} could not be generated; it can be regenerated later.");
        }

        return $id;
    }
}
