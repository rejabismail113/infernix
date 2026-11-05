<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M03 document engine: versioned templates, commercial documents (quotation / invoice / credit note / receipt /
 * payment voucher register), normalised lines, receipts and allocations.
 *
 * Database-level rules (they hold even if application code is bypassed):
 *  - issued documents are immutable: only status/settlement fields, the one-time PDF link and void metadata may
 *    change, through the allowed status transitions; numbered documents are never deleted (M03-07, §6.3);
 *  - lines can only change while the document is a draft;
 *  - template versions are append-only;
 *  - receipt + credit-note allocations can never exceed the invoice total (UAT-04, M03-03);
 *  - a receipt row cannot exist without its posted journal (M03-04).
 *
 * @see SRS M03-01, M03-02, M03-03, M03-04, M03-06, M03-07, M03-08, UAT-04, UAT-06
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('document_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('view_name', 120); // Blade view shipped in code — never user-supplied Blade (A-36)
            $table->jsonb('settings'); // accent colour, footer, default terms, show bank details…
            $table->text('notes')->nullable();
            $table->timestampTz('effective_from')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_template_id', 'version']);
        });

        Schema::create('commercial_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->string('document_no', 40)->nullable(); // allocated at ISSUE (gap-free), kept on void
            $table->unsignedInteger('revision_no')->default(0);
            $table->foreignId('template_version_id')->nullable()->constrained('document_template_versions');
            $table->jsonb('issuer_profile_snapshot')->nullable();
            $table->string('counterparty_type', 16)->default('client');
            $table->unsignedBigInteger('counterparty_id')->nullable();
            $table->jsonb('counterparty_snapshot')->nullable();
            $table->jsonb('lines_snapshot')->nullable(); // lines as issued (JSON copy of document_lines / source data)
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('related_document_id')->nullable()->constrained('commercial_documents'); // credit note → invoice
            $table->foreignId('supersedes_id')->nullable()->constrained('commercial_documents'); // revision chain
            $table->string('title')->nullable();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_total', 18, 4)->default(0);
            $table->decimal('tax_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->char('currency', 3)->default('MYR');
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('settlement_status', 16)->nullable(); // invoices: unpaid | partially_paid | paid
            $table->decimal('amount_received', 18, 4)->default(0);
            $table->decimal('amount_credited', 18, 4)->default(0);
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->string('source_type', 24)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents');
            $table->foreignId('void_pdf_document_id')->nullable()->constrained('documents');
            $table->foreignId('journal_id')->nullable()->constrained('journals');
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals');
            $table->string('idempotency_key', 120)->nullable();
            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('client_response_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampsTz();
            $table->index(['type', 'status']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement('CREATE UNIQUE INDEX commercial_documents_number_unique ON commercial_documents (company_id, type, document_no, revision_no) WHERE document_no IS NOT NULL');
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_type_check CHECK (type IN ('quotation','invoice','credit_note','receipt','payment_voucher','client_po','vendor_po','memo','letter','certificate'))");
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_status_check CHECK (status IN ('draft','pending_approval','approved','issued','accepted','rejected','void','superseded'))");
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_settlement_check CHECK (settlement_status IS NULL OR settlement_status IN ('unpaid','partially_paid','paid'))");
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_currency_check CHECK (currency = 'MYR')");
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_counterparty_check CHECK (counterparty_type IN ('client','vendor','staff','participant','payee'))");
        DB::statement('ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_amounts_check CHECK (subtotal >= 0 AND tax_total >= 0 AND total >= 0 AND amount_received >= 0 AND amount_credited >= 0 AND amount_received + amount_credited <= total)');
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_issued_shape CHECK (status IN ('draft','pending_approval','approved') OR (document_no IS NOT NULL AND issuer_profile_snapshot IS NOT NULL AND issue_date IS NOT NULL))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_source_quotation_fk FOREIGN KEY (source_quotation_id) REFERENCES commercial_documents(id)');

        Schema::create('document_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('commercial_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 12, 4);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 18, 4);
            $table->decimal('discount', 18, 4)->default(0); // absolute amount off the line
            $table->foreignId('tax_code_id')->nullable()->constrained('dictionary_items');
            $table->decimal('tax_rate', 7, 4)->default(0); // snapshot of the tax code rate (%)
            $table->decimal('net_amount', 18, 4);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4);
            $table->foreignId('revenue_account_id')->nullable()->constrained('chart_accounts');
            $table->unsignedBigInteger('milestone_id')->nullable(); // ⚑ milestones arrive with M02 (Prompt 4)
            $table->foreignId('source_line_id')->nullable()->constrained('document_lines');
            $table->timestampsTz();
            $table->unique(['commercial_document_id', 'line_no']);
        });
        DB::statement('ALTER TABLE document_lines ADD CONSTRAINT document_lines_amounts_check CHECK (quantity > 0 AND unit_price >= 0 AND discount >= 0 AND net_amount >= 0 AND tax_amount >= 0 AND line_total = net_amount + tax_amount)');

        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('receipt_no', 40)->unique();
            $table->date('receipt_date');
            $table->foreignId('bank_account_id')->constrained('bank_accounts');
            $table->decimal('amount', 18, 4);
            $table->decimal('unapplied_amount', 18, 4)->default(0);
            $table->string('method', 16);
            $table->string('reference', 120)->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payer_name');
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->constrained('journals'); // NOT NULL: no receipt without a posting (M03-04)
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals');
            $table->string('status', 12)->default('posted');
            $table->foreignId('pdf_document_id')->nullable()->constrained('documents');
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_status_check CHECK (status IN ('posted','void'))");
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_method_check CHECK (method IN ('transfer','duitnow','cheque','cash','card','fpx'))");
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_amount_check CHECK (amount > 0 AND unapplied_amount >= 0 AND unapplied_amount <= amount)');

        Schema::create('receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['receipt_id', 'invoice_id']);
        });
        DB::statement('ALTER TABLE receipt_allocations ADD CONSTRAINT receipt_allocations_amount_check CHECK (amount > 0)');

        Schema::create('credit_note_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('commercial_documents')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['credit_note_id', 'invoice_id']);
        });
        DB::statement('ALTER TABLE credit_note_allocations ADD CONSTRAINT credit_note_allocations_amount_check CHECK (amount > 0)');

        $this->triggers();
    }

    private function triggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_template_versions_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'document_template_versions are append-only: create a new version instead (M03-07)';
END $$ LANGUAGE plpgsql;
CREATE TRIGGER document_template_versions_append_only BEFORE UPDATE OR DELETE ON document_template_versions
    FOR EACH ROW EXECUTE FUNCTION document_template_versions_append_only();

CREATE OR REPLACE FUNCTION commercial_documents_immutable() RETURNS trigger AS $$
DECLARE
    mutable text[] := ARRAY['status','settlement_status','amount_received','amount_credited','pdf_document_id',
        'void_pdf_document_id','reversal_journal_id','void_reason','voided_at','client_response_at','updated_at'];
    old_frozen jsonb;
    new_frozen jsonb;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status NOT IN ('draft','pending_approval','approved') THEN
            RAISE EXCEPTION 'issued commercial document % is never deleted; void it instead (M03-07)', OLD.document_no;
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status NOT IN ('issued','accepted','rejected','void','superseded') THEN
        RETURN NEW;
    END IF;
    old_frozen := to_jsonb(OLD) - mutable;
    new_frozen := to_jsonb(NEW) - mutable;
    IF old_frozen IS DISTINCT FROM new_frozen THEN
        RAISE EXCEPTION 'issued commercial document % is immutable (snapshot, lines, totals, number) (M03-07)', OLD.document_no;
    END IF;
    IF OLD.pdf_document_id IS NOT NULL AND NEW.pdf_document_id IS DISTINCT FROM OLD.pdf_document_id THEN
        RAISE EXCEPTION 'issued PDF of % is frozen and cannot be replaced', OLD.document_no;
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
        (OLD.status = 'issued' AND NEW.status IN ('accepted','rejected','void','superseded')) OR
        (OLD.status = 'accepted' AND NEW.status IN ('void','superseded')) OR
        (OLD.status = 'rejected' AND NEW.status IN ('void','superseded'))
    ) THEN
        RAISE EXCEPTION 'illegal status change % -> % on issued document %', OLD.status, NEW.status, OLD.document_no;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER commercial_documents_immutable BEFORE UPDATE OR DELETE ON commercial_documents
    FOR EACH ROW EXECUTE FUNCTION commercial_documents_immutable();

CREATE OR REPLACE FUNCTION document_lines_draft_only() RETURNS trigger AS $$
DECLARE
    doc_status text;
    doc_id bigint;
BEGIN
    doc_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.commercial_document_id ELSE NEW.commercial_document_id END;
    SELECT status INTO doc_status FROM commercial_documents WHERE id = doc_id;
    IF doc_status IS NOT NULL AND doc_status <> 'draft' THEN
        RAISE EXCEPTION 'lines of a % document cannot change (only drafts are editable)', doc_status;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER document_lines_draft_only BEFORE INSERT OR UPDATE OR DELETE ON document_lines
    FOR EACH ROW EXECUTE FUNCTION document_lines_draft_only();

CREATE OR REPLACE FUNCTION receipts_immutable() RETURNS trigger AS $$
DECLARE
    mutable text[] := ARRAY['status','reversal_journal_id','pdf_document_id','void_reason','voided_at','updated_at'];
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'receipt % is never deleted; void it instead', OLD.receipt_no;
    END IF;
    IF (to_jsonb(OLD) - mutable) IS DISTINCT FROM (to_jsonb(NEW) - mutable) THEN
        RAISE EXCEPTION 'posted receipt % is immutable', OLD.receipt_no;
    END IF;
    IF OLD.pdf_document_id IS NOT NULL AND NEW.pdf_document_id IS DISTINCT FROM OLD.pdf_document_id THEN
        RAISE EXCEPTION 'receipt PDF of % is frozen', OLD.receipt_no;
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (OLD.status = 'posted' AND NEW.status = 'void') THEN
        RAISE EXCEPTION 'illegal receipt status change % -> %', OLD.status, NEW.status;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER receipts_immutable BEFORE UPDATE OR DELETE ON receipts
    FOR EACH ROW EXECUTE FUNCTION receipts_immutable();

-- Receipts + credit notes allocated to an invoice never exceed its total (UAT-04, M03-03).
CREATE OR REPLACE FUNCTION invoice_allocation_ceiling() RETURNS trigger AS $$
DECLARE
    inv record;
    allocated numeric(18,4);
BEGIN
    IF NEW.voided_at IS NOT NULL THEN RETURN NEW; END IF;
    SELECT id, type, status, total, document_no INTO inv FROM commercial_documents WHERE id = NEW.invoice_id FOR UPDATE;
    IF inv.type <> 'invoice' OR inv.status NOT IN ('issued','accepted') THEN
        RAISE EXCEPTION 'allocations can only be made to an issued invoice';
    END IF;
    SELECT COALESCE((SELECT SUM(amount) FROM receipt_allocations WHERE invoice_id = NEW.invoice_id AND voided_at IS NULL
                       AND NOT (TG_TABLE_NAME = 'receipt_allocations' AND id = NEW.id)), 0)
         + COALESCE((SELECT SUM(amount) FROM credit_note_allocations WHERE invoice_id = NEW.invoice_id AND voided_at IS NULL
                       AND NOT (TG_TABLE_NAME = 'credit_note_allocations' AND id = NEW.id)), 0)
      INTO allocated;
    IF allocated + NEW.amount > inv.total THEN
        RAISE EXCEPTION 'over-allocation: invoice % total %, already settled %, new %', inv.document_no, inv.total, allocated, NEW.amount;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER receipt_allocations_ceiling BEFORE INSERT OR UPDATE ON receipt_allocations
    FOR EACH ROW EXECUTE FUNCTION invoice_allocation_ceiling();
CREATE TRIGGER credit_note_allocations_ceiling BEFORE INSERT OR UPDATE ON credit_note_allocations
    FOR EACH ROW EXECUTE FUNCTION invoice_allocation_ceiling();

CREATE OR REPLACE FUNCTION allocations_no_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'allocations are never deleted; void the receipt or credit note';
END $$ LANGUAGE plpgsql;
CREATE TRIGGER receipt_allocations_no_delete BEFORE DELETE ON receipt_allocations FOR EACH ROW EXECUTE FUNCTION allocations_no_delete();
CREATE TRIGGER credit_note_allocations_no_delete BEFORE DELETE ON credit_note_allocations FOR EACH ROW EXECUTE FUNCTION allocations_no_delete();
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_source_quotation_fk');
        Schema::dropIfExists('credit_note_allocations');
        Schema::dropIfExists('receipt_allocations');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('document_lines');
        Schema::dropIfExists('commercial_documents');
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
        DB::unprepared('DROP FUNCTION IF EXISTS invoice_allocation_ceiling, allocations_no_delete, receipts_immutable, document_lines_draft_only, commercial_documents_immutable, document_template_versions_append_only CASCADE');
    }
};
