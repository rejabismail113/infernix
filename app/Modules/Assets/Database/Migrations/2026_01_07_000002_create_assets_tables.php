<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M05 Assets. An asset registered from a purchase REFERENCES the posted purchase (transaction or bill line) and
 * never creates a second expense (UAT-03): the total cost registered against one purchase line can never exceed
 * that line (trigger assets_purchase_ceiling). Disposals post through PostingService via the AssetDisposed event.
 *
 * @see SRS M05-01, M05-02, M05-03, M05-04, M05-05, WF-02, UAT-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('default_class', 24)->default('capital_asset');
            $table->unsignedSmallInteger('useful_life_months')->default(36);
            $table->decimal('residual_percent', 5, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_class_check CHECK (default_class IN ('capital_asset','low_value_equipment'))");

        Schema::create('asset_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_line_id');
            $table->text('description');
            $table->decimal('amount', 18, 4);
            $table->date('purchase_date');
            $table->string('status', 12)->default('pending');
            $table->text('dismissed_reason')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['source_type', 'source_line_id']);
        });
        DB::statement("ALTER TABLE asset_candidates ADD CONSTRAINT asset_candidates_source_check CHECK (source_type IN ('transaction','bill'))");
        DB::statement("ALTER TABLE asset_candidates ADD CONSTRAINT asset_candidates_status_check CHECK (status IN ('pending','registered','dismissed'))");

        Schema::create('assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('asset_no', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('asset_categories')->restrictOnDelete();
            $table->string('asset_class', 24)->default('capital_asset');
            $table->string('serial_no')->nullable();
            $table->string('model')->nullable();
            $table->string('location')->nullable();
            $table->string('status', 16)->default('in_store');
            $table->string('acquisition_type', 16)->default('purchase');
            $table->string('purchase_source_type', 16)->nullable();
            $table->foreignId('purchase_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('purchase_bill_id')->nullable()->constrained('bills')->restrictOnDelete();
            $table->unsignedBigInteger('purchase_line_id')->nullable();
            $table->foreignId('candidate_id')->nullable()->constrained('asset_candidates')->restrictOnDelete();
            $table->date('acquired_on');
            $table->decimal('cost', 18, 4);
            $table->decimal('residual_value', 18, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months')->default(36);
            $table->string('depreciation_method', 16)->default('straight_line');
            $table->date('warranty_expires_on')->nullable();
            $table->unsignedBigInteger('custodian_staff_id')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('disposed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['purchase_source_type', 'purchase_line_id']);
            $table->index('status');
        });
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_class_check CHECK (asset_class IN ('capital_asset','low_value_equipment'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_status_check CHECK (status IN ('in_store','assigned','in_repair','disposed','lost'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_acquisition_check CHECK (acquisition_type IN ('purchase','opening_balance','donation'))");
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_method_check CHECK (depreciation_method IN ('straight_line','none'))");
        DB::statement('ALTER TABLE assets ADD CONSTRAINT assets_cost_check CHECK (cost >= 0 AND residual_value >= 0 AND residual_value <= cost)');
        // A purchased asset references exactly one posted purchase line; other acquisitions reference none.
        DB::statement("ALTER TABLE assets ADD CONSTRAINT assets_purchase_ref_check CHECK (
            (acquisition_type = 'purchase' AND purchase_line_id IS NOT NULL AND (
                (purchase_source_type = 'transaction' AND purchase_transaction_id IS NOT NULL AND purchase_bill_id IS NULL) OR
                (purchase_source_type = 'bill' AND purchase_bill_id IS NOT NULL AND purchase_transaction_id IS NULL)))
            OR (acquisition_type <> 'purchase' AND purchase_line_id IS NULL AND purchase_transaction_id IS NULL AND purchase_bill_id IS NULL))");

        Schema::create('asset_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('staff_profile_id')->nullable();
            $table->string('location')->nullable();
            $table->date('assigned_on');
            $table->date('expected_return_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->string('condition_out')->nullable();
            $table->string('condition_in')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('returned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['staff_profile_id', 'returned_on']);
        });
        DB::statement('CREATE UNIQUE INDEX asset_assignments_one_open ON asset_assignments (asset_id) WHERE returned_on IS NULL');

        Schema::create('asset_maintenance', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16)->default('repair');
            $table->date('reported_on');
            $table->date('completed_on')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('description');
            $table->decimal('estimated_cost', 18, 4)->nullable();
            $table->string('status', 12)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE asset_maintenance ADD CONSTRAINT asset_maintenance_kind_check CHECK (kind IN ('repair','service','inspection','upgrade'))");
        DB::statement("ALTER TABLE asset_maintenance ADD CONSTRAINT asset_maintenance_status_check CHECK (status IN ('open','completed','cancelled'))");

        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('disposal_no', 32)->unique();
            $table->string('method', 16);
            $table->date('disposal_date');
            $table->text('reason');
            $table->decimal('expected_proceeds', 18, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->decimal('cost_snapshot', 18, 4)->nullable();
            $table->decimal('accumulated_snapshot', 18, 4)->nullable();
            $table->decimal('nbv_snapshot', 18, 4)->nullable();
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_method_check CHECK (method IN ('sale','scrap','donation','loss','trade_in'))");
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_status_check CHECK (status IN ('draft','pending_approval','approved','rejected','cancelled'))");
        DB::statement('ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_proceeds_check CHECK (expected_proceeds >= 0)');
        DB::statement("CREATE UNIQUE INDEX asset_disposals_one_live ON asset_disposals (asset_id) WHERE status NOT IN ('rejected','cancelled')");

        Schema::create('asset_depreciation', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->date('period_end');
            $table->decimal('amount', 18, 4);
            $table->decimal('accumulated', 18, 4);
            $table->decimal('nbv', 18, 4);
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->unique(['asset_id', 'period_end']);
        });

        DB::unprepared(<<<'SQL'
-- The cost registered against one purchase line can never exceed the line (no second expense, UAT-03).
CREATE OR REPLACE FUNCTION assets_purchase_ceiling() RETURNS trigger AS $$
DECLARE line_amount numeric; registered numeric; source_ok boolean;
BEGIN
    IF NEW.acquisition_type <> 'purchase' THEN RETURN NEW; END IF;
    IF NEW.purchase_source_type = 'transaction' THEN
        SELECT l.amount, (t.status = 'posted' AND t.capitalization_class = 'CAPITAL_ASSET') INTO line_amount, source_ok
            FROM transaction_lines l JOIN transactions t ON t.id = l.transaction_id
            WHERE l.id = NEW.purchase_line_id AND t.id = NEW.purchase_transaction_id FOR UPDATE OF l;
    ELSE
        SELECT l.amount, (b.status IN ('approved','partially_paid','paid') AND l.capitalization_class = 'CAPITAL_ASSET') INTO line_amount, source_ok
            FROM bill_lines l JOIN bills b ON b.id = l.bill_id
            WHERE l.id = NEW.purchase_line_id AND b.id = NEW.purchase_bill_id FOR UPDATE OF l;
    END IF;
    IF line_amount IS NULL THEN
        RAISE EXCEPTION 'purchase line % does not belong to the referenced purchase', NEW.purchase_line_id;
    END IF;
    IF NOT source_ok THEN
        RAISE EXCEPTION 'purchase line % is not a posted capital purchase', NEW.purchase_line_id;
    END IF;
    SELECT COALESCE(sum(cost), 0) INTO registered FROM assets
        WHERE purchase_source_type = NEW.purchase_source_type AND purchase_line_id = NEW.purchase_line_id AND id IS DISTINCT FROM NEW.id;
    IF registered + NEW.cost > line_amount THEN
        RAISE EXCEPTION 'asset cost exceeds the purchase line: % registered of %, % requested (UAT-03)', registered, line_amount, NEW.cost;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER assets_purchase_ceiling BEFORE INSERT OR UPDATE OF cost, purchase_line_id, purchase_source_type, acquisition_type ON assets
    FOR EACH ROW EXECUTE FUNCTION assets_purchase_ceiling();

-- Posted depreciation rows are immutable.
CREATE OR REPLACE FUNCTION asset_depreciation_posted() RETURNS trigger AS $$
BEGIN
    IF OLD.journal_id IS NOT NULL THEN
        RAISE EXCEPTION 'posted depreciation % is immutable', OLD.id;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER asset_depreciation_posted BEFORE UPDATE OR DELETE ON asset_depreciation FOR EACH ROW EXECUTE FUNCTION asset_depreciation_posted();
SQL);
    }

    public function down(): void
    {
        foreach (['asset_depreciation', 'asset_disposals', 'asset_maintenance', 'asset_assignments', 'assets', 'asset_candidates', 'asset_categories'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS assets_purchase_ceiling() CASCADE; DROP FUNCTION IF EXISTS asset_depreciation_posted() CASCADE;');
    }
};
