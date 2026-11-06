<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Services;

use App\Kernel\Money\Money;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Enums\DocumentType;
use App\Modules\Commercial\Exceptions\CommercialRejected;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\DocumentTemplateVersion;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Brick\Money\Money as BrickMoney;

/**
 * Builds the template view-model for an ISSUED document purely from what was snapshotted on it (issuer,
 * counterparty, lines, totals, template version), so a re-render — e.g. the VOID copy — shows exactly what was
 * issued. Template layouts are Blade views shipped in code; only their settings are edited in the app (A-36).
 *
 * @see SRS M03-07, M03-08
 */
final class TemplateRenderer
{
    public function currentVersion(DocumentType $type): DocumentTemplateVersion
    {
        $template = DocumentTemplate::query()->where('code', $type->value)->where('active', true)->first()
            ?? throw new CommercialRejected("No active template is configured for {$type->label()} documents.");

        return $template->current()->first() ?? throw new CommercialRejected("Template {$type->value} has no published version.");
    }

    public function html(CommercialDocument $doc, bool $void = false): string
    {
        if ($doc->document_no === null || $doc->issuer_profile_snapshot === null) {
            throw new CommercialRejected('Only issued documents can be rendered.');
        }
        $version = $doc->template_version_id !== null ? DocumentTemplateVersion::query()->findOrFail($doc->template_version_id) : $this->currentVersion($doc->type);

        return view($version->view_name, $this->viewModel($doc, $version, $void))->render();
    }

    /** @return array<string, mixed> */
    public function viewModel(CommercialDocument $doc, DocumentTemplateVersion $version, bool $void): array
    {
        $settings = $version->settings;
        $snap = $doc->lines_snapshot ?? [];
        $extra = $snap['extra'] ?? [];
        $issuer = $doc->issuer_profile_snapshot ?? [];
        $type = $doc->type;
        $detailed = $type->isBuilt();
        $references = array_filter([
            'Our ref' => $extra['our_ref'] ?? null,
            'Invoice' => $extra['invoice_no'] ?? null,
            'Quotation' => $extra['quotation_no'] ?? null,
            'Project' => $extra['project'] ?? null,
        ], fn ($v) => is_string($v) && $v !== '');
        $facts = array_filter(match ($type) {
            DocumentType::Receipt => ['Received via' => $extra['method'] ?? null, 'Reference' => $extra['reference'] ?? null, 'Into account' => $extra['bank'] ?? null],
            DocumentType::PaymentVoucher => ['Paid via' => $extra['method'] ?? null, 'Reference' => $extra['reference'] ?? null, 'From account' => $extra['bank'] ?? null, 'Memo' => $extra['memo'] ?? null],
            DocumentType::Invoice => ['Payment terms' => isset($extra['terms_days']) ? $extra['terms_days'].' days' : null],
            default => [],
        }, fn ($v) => is_string($v) && $v !== '');
        $signatory = ['label' => 'Authorised signatory', 'name' => (string) ($issuer['signatory_name'] ?? ''), 'title' => $issuer['signatory_title'] ?? null];
        $signatures = match ($type) {
            DocumentType::PaymentVoucher => [
                ['label' => 'Prepared by', 'name' => (string) ($extra['prepared_by'] ?? ''), 'title' => null],
                ['label' => 'Approved by', 'name' => (string) ($extra['approved_by'] ?? ''), 'title' => ($extra['owner_override'] ?? false) ? 'owner override' : null],
                $signatory,
            ],
            DocumentType::Receipt => [['label' => 'Received by', 'name' => (string) ($extra['received_by'] ?? ''), 'title' => null], $signatory],
            DocumentType::Quotation => [$signatory, ['label' => 'Accepted by (client)', 'name' => '', 'title' => null]],
            default => [$signatory],
        };
        $logoPath = public_path((string) ($issuer['logo']['source'] ?? 'images/infernix-logo.svg'));
        $verifyUrl = $doc->verification_token !== null ? route('verify.certificate', $doc->verification_token) : null;

        return [
            'settings' => $settings,
            'template' => "{$version->template->code} v{$version->version}",
            'void' => $void || $doc->status === DocumentStatus::Void,
            'detailed' => $detailed,
            'issuer' => $issuer,
            'counterparty' => $doc->counterparty_snapshot ?? [],
            'logo' => is_file($logoPath) ? 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($logoPath)) : null,
            'doc' => [
                'label' => $type->label(), 'number' => $doc->document_no, 'revision' => $doc->revision_no, 'title' => $doc->title,
                'issue_date' => $doc->issue_date?->format('d/m/Y'), 'due_date' => $doc->due_date?->format('d/m/Y'), 'valid_until' => $doc->valid_until?->format('d/m/Y'),
                'terms' => $doc->terms, 'references' => $references, 'facts' => $facts, 'void_reason' => $doc->void_reason,
                'counterparty_label' => match ($type) {
                    DocumentType::Quotation => 'Prepared for',
                    DocumentType::VendorPo => 'Supplier',
                    DocumentType::Memo, DocumentType::AppointmentLetter, DocumentType::Letter => 'To',
                    DocumentType::Certificate => 'Awarded to',
                    DocumentType::Receipt => 'Received from',
                    DocumentType::PaymentVoucher => 'Pay to',
                    default => 'Bill to',
                },
            ],
            'lines' => $snap['lines'] ?? [],
            'totals' => [
                'subtotal' => $this->s($doc->subtotal), 'tax' => $this->s($doc->tax_total), 'total' => $this->s($doc->total),
            ],
            'signatures' => $signatures,
            'subject' => $doc->subject,
            'body' => $doc->body,
            'effective_date' => $doc->effective_date?->format('j F Y'),
            'external_ref' => $doc->external_ref,
            'trainer' => $extra['trainer'] ?? null,
            'verify_url' => $verifyUrl,
            'qr' => $verifyUrl !== null ? $this->qr($verifyUrl) : null,
        ];
    }

    /** SVG QR code as a data URI (certificate verification link). */
    private function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(140, 1), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function s(BrickMoney $m): string
    {
        return (string) $m->getAmount();
    }

    /** Sample data for the template library preview (never a real document). */
    public function previewHtml(DocumentTemplateVersion $version): string
    {
        $type = DocumentType::from($version->template->code);
        $class = DocumentRegistry::modelFor($type);
        /** @var CommercialDocument $doc */
        $doc = new $class;
        $doc->forceFill([
            'document_no' => strtoupper(substr($type->sequence(), 0, 3)).'-PREVIEW', 'revision_no' => 0, 'issue_date' => today(), 'due_date' => today()->addDays(30),
            'valid_until' => $type === DocumentType::Quotation ? today()->addDays(30) : null, 'title' => 'Sample document — preview only',
            'terms' => $version->settings['default_terms'] ?? null, 'status' => DocumentStatus::Issued,
            'subtotal' => Money::myr('1000.00'), 'tax_total' => Money::zero(), 'total' => Money::myr('1000.00'),
            'counterparty_snapshot' => ['name' => 'Sample Client Sdn Bhd', 'registration_no' => '202601000000', 'address' => ['line1' => 'Jalan Contoh 1', 'city' => 'Kuala Lumpur'], 'email' => 'accounts@example.com'],
            'lines_snapshot' => ['lines' => $type->isBuilt()
                ? [['line_no' => 1, 'description' => 'Sample service line', 'quantity' => '1.0000', 'unit' => 'lot', 'unit_price' => '1000.00', 'discount' => '0.00', 'tax_rate' => '0.0000', 'net' => '1000.00', 'tax' => '0.00', 'total' => '1000.00']]
                : [['ref' => 'REF-0001', 'description' => 'Sample settlement', 'amount' => '1000.00']], 'extra' => ['prepared_by' => 'Preparer', 'approved_by' => 'Approver', 'received_by' => 'Finance']],
            'issuer_profile_snapshot' => app(IssuerProfile::class)->snapshot(),
        ]);
        $doc->setAttribute('type', $type);

        return view($version->view_name, $this->viewModel($doc, $version, false))->render();
    }
}
