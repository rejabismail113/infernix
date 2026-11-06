<?php

declare(strict_types=1);

namespace App\Modules\Commercial;

use App\Kernel\Approval\ApprovalService;
use App\Kernel\Documents\Contracts\GeneratedDocumentIssuer;
use App\Kernel\Documents\Contracts\HtmlToPdf;
use App\Kernel\Documents\Contracts\IssuedDocumentRenderer;
use App\Kernel\Documents\Contracts\VendorPurchaseOrders;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkableEntityType;
use App\Kernel\Notifications\PendingApprovalRegistry;
use App\Kernel\Reports\ReportCatalog;
use App\Kernel\Reports\ReportDefinition;
use App\Modules\Commercial\EInvoice\EInvoiceGateway;
use App\Modules\Commercial\EInvoice\EnqueueIssuedInvoice;
use App\Modules\Commercial\EInvoice\StubEInvoiceGateway;
use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Events\DocumentIssued;
use App\Modules\Commercial\Models\AppointmentLetter;
use App\Modules\Commercial\Models\Certificate;
use App\Modules\Commercial\Models\ClientPurchaseOrder;
use App\Modules\Commercial\Models\CommercialDocument;
use App\Modules\Commercial\Models\CreditNote;
use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\Invoice;
use App\Modules\Commercial\Models\Letter;
use App\Modules\Commercial\Models\Memo;
use App\Modules\Commercial\Models\Quotation;
use App\Modules\Commercial\Models\Receipt;
use App\Modules\Commercial\Models\ReceiptDocument;
use App\Modules\Commercial\Models\VendorPurchaseOrder;
use App\Modules\Commercial\Models\VoucherDocument;
use App\Modules\Commercial\Policies\AppointmentLetterPolicy;
use App\Modules\Commercial\Policies\CertificatePolicy;
use App\Modules\Commercial\Policies\ClientPurchaseOrderPolicy;
use App\Modules\Commercial\Policies\CreditNotePolicy;
use App\Modules\Commercial\Policies\DocumentTemplatePolicy;
use App\Modules\Commercial\Policies\InvoicePolicy;
use App\Modules\Commercial\Policies\LetterPolicy;
use App\Modules\Commercial\Policies\MemoPolicy;
use App\Modules\Commercial\Policies\QuotationPolicy;
use App\Modules\Commercial\Policies\ReceiptDocumentPolicy;
use App\Modules\Commercial\Policies\ReceiptPolicy;
use App\Modules\Commercial\Policies\VendorPurchaseOrderPolicy;
use App\Modules\Commercial\Policies\VoucherDocumentPolicy;
use App\Modules\Commercial\Reports\CommercialReports;
use App\Modules\Commercial\Services\CommercialIssuedDocumentRenderer;
use App\Modules\Commercial\Services\GeneratorService;
use App\Modules\Commercial\Services\PdfRenderer;
use App\Modules\Commercial\Services\VendorPurchaseOrderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Module M03 — Quotation, Billing & Document Generation.
 *
 * @see SRS §4 M03
 * @see docs/ARCHITECTURE.md
 */
final class CommercialServiceProvider extends ServiceProvider
{
    public const MODULE_ID = 'M03';

    public function register(): void
    {
        $this->app->bind(IssuedDocumentRenderer::class, CommercialIssuedDocumentRenderer::class);
        $this->app->bind(GeneratedDocumentIssuer::class, GeneratorService::class);
        $this->app->bind(HtmlToPdf::class, PdfRenderer::class);
        $this->app->bind(VendorPurchaseOrders::class, VendorPurchaseOrderService::class);
        // Only the stub exists until MyInvois obligations are confirmed by the accountant (A-82).
        $this->app->bind(EInvoiceGateway::class, StubEInvoiceGateway::class);
    }

    public function boot(): void
    {
        Event::listen(DocumentIssued::class, EnqueueIssuedInvoice::class);
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/Routes/web.php');

        foreach ([
            Quotation::class => QuotationPolicy::class, Invoice::class => InvoicePolicy::class, CreditNote::class => CreditNotePolicy::class,
            Receipt::class => ReceiptPolicy::class, ReceiptDocument::class => ReceiptDocumentPolicy::class, VoucherDocument::class => VoucherDocumentPolicy::class,
            DocumentTemplate::class => DocumentTemplatePolicy::class,
            ClientPurchaseOrder::class => ClientPurchaseOrderPolicy::class,
            VendorPurchaseOrder::class => VendorPurchaseOrderPolicy::class,
            Memo::class => MemoPolicy::class,
            Letter::class => LetterPolicy::class,
            AppointmentLetter::class => AppointmentLetterPolicy::class,
            Certificate::class => CertificatePolicy::class,
        ] as $model => $policy) {
            Gate::policy($model, $policy);
        }

        $catalog = $this->app->make(ReportCatalog::class);
        $range = fn (array $f): array => [Carbon::parse($f['from'] ?? today()->startOfYear()->toDateString()), Carbon::parse($f['to'] ?? today()->toDateString())];
        $catalog->register(new ReportDefinition('commercial.quotation-register', 'Quotation register', 'Commercial', 'quotations', fn (array $f): array => app(CommercialReports::class)->quotationRegister(...$range($f))));
        $catalog->register(new ReportDefinition('commercial.invoice-register', 'Invoice register', 'Commercial', 'invoices', fn (array $f): array => app(CommercialReports::class)->invoiceRegister(...$range($f))));
        $catalog->register(new ReportDefinition('commercial.issuance-history', 'Document issuance history', 'Commercial', 'invoices', fn (array $f): array => app(CommercialReports::class)->issuanceHistory(...$range($f))));

        // Daily approval reminders (G-05).
        $this->app->make(PendingApprovalRegistry::class)->register('commercial', function (): array {
            $items = [];
            foreach (['quotations' => Quotation::class, 'invoices' => Invoice::class, 'credit-notes' => CreditNote::class] as $route => $class) {
                foreach ($class::query()->where('status', DocumentStatus::PendingApproval->value)->limit(200)->get() as $doc) {
                    /** @var CommercialDocument $doc */
                    $items[] = ['key' => $doc->type->value.':'.$doc->id.':L'.$doc->current_level, 'title' => $doc->type->label().' '.$doc->displayNo().' for '.$doc->counterpartyName().' (RM '.$doc->total->getAmount().')',
                        'url' => route("commercial.{$route}.show", $doc->id), 'roles' => app(ApprovalService::class)->rolesFor($doc->type->value, $doc->total, $doc->current_level), 'preparer_id' => $doc->created_by];
                }
            }

            return $items;
        });

        // Issued PDFs and supporting evidence live in the Document Centre, linked to these records (M06-01).
        $registry = $this->app->make(DocumentLinkRegistry::class);
        $registry->register(new LinkableEntityType('quotation', Quotation::class, 'Quotation', ['issued_document', 'client_po', 'evidence', 'attachment'], labelFor: fn (Quotation $q): string => $q->displayNo().' '.$q->counterpartyName()));
        $registry->register(new LinkableEntityType('invoice', Invoice::class, 'Invoice', ['issued_document', 'client_po', 'evidence', 'attachment'], labelFor: fn (Invoice $i): string => $i->displayNo().' '.$i->counterpartyName()));
        $registry->register(new LinkableEntityType('credit_note', CreditNote::class, 'Credit note', ['issued_document', 'evidence', 'attachment'], labelFor: fn (CreditNote $c): string => $c->displayNo()));
        $registry->register(new LinkableEntityType('client_po', ClientPurchaseOrder::class, 'Client PO / LOA', ['client_po', 'contract', 'evidence', 'attachment'], labelFor: fn (CommercialDocument $c): string => $c->displayNo().' '.$c->external_ref));
        $registry->register(new LinkableEntityType('vendor_po', VendorPurchaseOrder::class, 'Vendor PO', ['issued_document', 'evidence', 'attachment'], labelFor: fn (CommercialDocument $c): string => $c->displayNo().' '.$c->counterpartyName()));
        foreach (['memo' => [Memo::class, 'Staff memo'], 'letter' => [Letter::class, 'Letter'], 'appointment_letter' => [AppointmentLetter::class, 'Appointment letter'], 'certificate' => [Certificate::class, 'Certificate']] as $code => [$class, $label]) {
            $registry->register(new LinkableEntityType($code, $class, $label, ['issued_document', 'evidence', 'attachment'], labelFor: fn (CommercialDocument $c): string => $c->displayNo().' '.$c->counterpartyName()));
        }
        $registry->register(new LinkableEntityType('receipt', Receipt::class, 'Receipt', ['issued_document', 'evidence', 'attachment'], labelFor: fn (Receipt $r): string => $r->receipt_no.' '.$r->payer_name));
    }
}
