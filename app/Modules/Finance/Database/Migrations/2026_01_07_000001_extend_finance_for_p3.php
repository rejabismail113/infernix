<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase P3 finance hooks. Operational modules LINK to finance records; they never copy them:
 *  - activity_id on expense / bill lines (claim lines already have it) — M07-02, M08-02
 *  - bills → vendor PO / goods receipt (procurement audit chain, M10-02)
 *  - case-insensitive unique vendor invoice number at DB level (M10-03 reinforcement)
 *  - finance_links: a renewal / repair / disposal / subscription points at an existing transaction, bill,
 *    claim or payment (M05-03, M05-04, M09-04, M10-05, WF-06). A link never creates money.
 *
 * @see SRS M05-01, M07-02, M08-02, M09-04, M10-02, M10-03, WF-02, WF-06, UAT-03, UAT-12
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('activity_id')->nullable()->index();
        });
        Schema::table('bill_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('activity_id')->nullable()->index();
        });
        Schema::table('bills', function (Blueprint $table): void {
            $table->unsignedBigInteger('vendor_po_id')->nullable()->index();
            $table->unsignedBigInteger('goods_receipt_id')->nullable()->index();
        });
        DB::statement('CREATE INDEX IF NOT EXISTS transactions_activity_idx ON transactions (activity_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS claim_lines_activity_idx ON claim_lines (activity_id)');
        DB::statement('CREATE UNIQUE INDEX bills_vendor_invoice_ci_unique ON bills (vendor_id, lower(btrim(vendor_invoice_no)))');

        Schema::create('finance_links', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->string('purpose', 24)->default('related');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['entity_type', 'entity_id', 'source_type', 'source_id']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("ALTER TABLE finance_links ADD CONSTRAINT finance_links_source_check CHECK (source_type IN ('transaction','bill','claim','payment'))");
        DB::statement("ALTER TABLE finance_links ADD CONSTRAINT finance_links_purpose_check CHECK (purpose IN ('related','purchase','repair','renewal','proceeds','spend','payment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_links');
        DB::statement('DROP INDEX IF EXISTS bills_vendor_invoice_ci_unique');
        Schema::table('bills', fn (Blueprint $t) => $t->dropColumn(['vendor_po_id', 'goods_receipt_id']));
        Schema::table('bill_lines', fn (Blueprint $t) => $t->dropColumn('activity_id'));
        Schema::table('transaction_lines', fn (Blueprint $t) => $t->dropColumn('activity_id'));
    }
};
