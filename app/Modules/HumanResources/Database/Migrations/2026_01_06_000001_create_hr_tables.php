<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M04 HR & part-time staff: profiles (NRIC/passport encrypted + masked), restricted bank details, versioned rate
 * agreements that lock once used (WF-05), timesheets against assigned projects (UAT-07), payment batches and the
 * memo inbox.
 *
 * Database rules: a locked engagement's rate fields never change and it is never deleted; a timesheet keeps the rate
 * snapshot it was calculated with; a timesheet can be in one batch only; batch lines never change once the batch is
 * approved.
 *
 * @see SRS M04-01 … M04-06, WF-05, UAT-07
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('staff_no', 20)->unique();
            $table->string('engagement_type', 16);
            $table->string('legal_name');
            $table->string('preferred_name')->nullable();
            $table->string('id_type', 10)->default('nric');
            $table->text('id_number')->nullable(); // encrypted cast; never sent unmasked except on audited reveal
            $table->string('id_last4', 4)->nullable(); // for masked display / matching only
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('position')->nullable();
            $table->date('joined_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->string('classification', 12); // employee | contractor (M04-05)
            $table->timestampTz('classification_reviewed_at')->nullable();
            $table->foreignId('classification_reviewed_by')->nullable()->constrained('users');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_engagement_check CHECK (engagement_type IN ('employee','part_time','freelancer','intern'))");
        DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_id_type_check CHECK (id_type IN ('nric','passport'))");
        DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_status_check CHECK (status IN ('active','inactive'))");
        DB::statement("ALTER TABLE staff_profiles ADD CONSTRAINT staff_profiles_classification_check CHECK (classification IN ('employee','contractor'))");

        Schema::create('staff_bank_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('bank_name'); // encrypted
            $table->text('account_no'); // encrypted
            $table->text('account_name'); // encrypted
            $table->string('account_last4', 4);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('engagements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('rate_type', 12);
            $table->decimal('rate_amount', 18, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('agreement_document_id')->nullable(); // ⚑ documents (contract / LOA)
            $table->timestampTz('locked_at')->nullable(); // set when first used in a calculation
            $table->foreignId('created_by')->constrained('users');
            $table->timestampsTz();
            $table->unique(['staff_profile_id', 'version']);
        });
        DB::statement("ALTER TABLE engagements ADD CONSTRAINT engagements_rate_type_check CHECK (rate_type IN ('hourly','daily','per_session','fixed'))");
        DB::statement('ALTER TABLE engagements ADD CONSTRAINT engagements_rate_check CHECK (rate_amount >= 0 AND (effective_to IS NULL OR effective_to >= effective_from))');
        DB::statement('ALTER TABLE engagements ADD CONSTRAINT engagements_agreement_fk FOREIGN KEY (agreement_document_id) REFERENCES documents(id)');

        Schema::create('timesheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 12)->default('draft');
            $table->decimal('total_quantity', 10, 2)->default(0);
            $table->foreignId('engagement_id')->nullable()->constrained(); // the locked rate version used
            $table->string('rate_type', 12)->nullable();
            $table->decimal('rate_snapshot', 18, 4)->nullable();
            $table->decimal('calculated_amount', 18, 4)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users');
            $table->timestampTz('calculated_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['staff_profile_id', 'period_start', 'period_end']);
        });
        DB::statement("ALTER TABLE timesheets ADD CONSTRAINT timesheets_status_check CHECK (status IN ('draft','submitted','verified','rejected','calculated','batched','approved','paid'))");
        DB::statement('ALTER TABLE timesheets ADD CONSTRAINT timesheets_period_check CHECK (period_end >= period_start AND period_end - period_start <= 62)');

        Schema::create('timesheet_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('timesheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->date('work_date');
            $table->decimal('quantity', 6, 2); // hours | days | sessions per the rate type
            $table->text('description')->nullable();
            $table->unsignedBigInteger('task_id')->nullable(); // ⚑ tasks (M02)
            $table->boolean('exception_granted')->default(false);
            $table->foreignId('exception_granted_by')->nullable()->constrained('users');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_qty_check CHECK (quantity > 0 AND quantity <= 24)');

        Schema::create('payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('batch_no', 30)->unique();
            $table->date('period_end');
            $table->string('status', 16)->default('draft');
            $table->string('payout_method', 12)->default('voucher');
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('accrual_journal_id')->nullable(); // ⚑ journals
            $table->unsignedBigInteger('payout_journal_id')->nullable(); // ⚑ journals
            $table->unsignedBigInteger('bank_account_id')->nullable(); // ⚑ bank_accounts
            $table->date('paid_on')->nullable();
            $table->string('voucher_no', 40)->nullable();
            $table->unsignedBigInteger('voucher_document_id')->nullable(); // ⚑ documents
            $table->unsignedBigInteger('handoff_document_id')->nullable(); // ⚑ documents (payroll CSV)
            $table->string('idempotency_key', 120)->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE payment_batches ADD CONSTRAINT payment_batches_status_check CHECK (status IN ('draft','pending_approval','approved','paid','cancelled'))");
        DB::statement("ALTER TABLE payment_batches ADD CONSTRAINT payment_batches_method_check CHECK (payout_method IN ('voucher','payroll_csv'))");
        foreach (['accrual_journal_id' => 'journals', 'payout_journal_id' => 'journals', 'bank_account_id' => 'bank_accounts', 'voucher_document_id' => 'documents', 'handoff_document_id' => 'documents'] as $col => $ref) {
            DB::statement("ALTER TABLE payment_batches ADD CONSTRAINT payment_batches_{$col}_fk FOREIGN KEY ({$col}) REFERENCES {$ref}(id)");
        }

        Schema::create('payment_batch_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('timesheet_id')->unique()->constrained()->restrictOnDelete(); // a timesheet is paid once
            $table->decimal('amount', 18, 4);
            $table->string('classification', 12);
            $table->boolean('contractor_invoice_required')->default(false);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE payment_batch_lines ADD CONSTRAINT payment_batch_lines_amount_check CHECK (amount > 0)');

        Schema::create('staff_memos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('staff_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('commercial_document_id'); // ⚑ commercial_documents (memo / letter)
            $table->string('subject');
            $table->timestampTz('delivered_at')->useCurrent();
            $table->timestampTz('read_at')->nullable();
            $table->unique(['staff_profile_id', 'commercial_document_id']);
        });
        DB::statement('ALTER TABLE staff_memos ADD CONSTRAINT staff_memos_document_fk FOREIGN KEY (commercial_document_id) REFERENCES commercial_documents(id)');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION engagements_locked() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.locked_at IS NOT NULL THEN
            RAISE EXCEPTION 'rate agreement v% is locked (already used for payment); add a new version instead (WF-05)', OLD.version;
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.locked_at IS NOT NULL AND (NEW.rate_type IS DISTINCT FROM OLD.rate_type OR NEW.rate_amount IS DISTINCT FROM OLD.rate_amount
        OR NEW.effective_from IS DISTINCT FROM OLD.effective_from OR NEW.staff_profile_id IS DISTINCT FROM OLD.staff_profile_id
        OR NEW.version IS DISTINCT FROM OLD.version OR NEW.locked_at IS DISTINCT FROM OLD.locked_at) THEN
        RAISE EXCEPTION 'rate agreement v% is locked (already used for payment); add a new version instead (WF-05)', OLD.version;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER engagements_locked BEFORE UPDATE OR DELETE ON engagements FOR EACH ROW EXECUTE FUNCTION engagements_locked();

CREATE OR REPLACE FUNCTION timesheets_calculation_frozen() RETURNS trigger AS $$
BEGIN
    IF OLD.status IN ('calculated','batched','approved','paid') AND (NEW.rate_snapshot IS DISTINCT FROM OLD.rate_snapshot
        OR NEW.calculated_amount IS DISTINCT FROM OLD.calculated_amount OR NEW.engagement_id IS DISTINCT FROM OLD.engagement_id
        OR NEW.total_quantity IS DISTINCT FROM OLD.total_quantity) THEN
        RAISE EXCEPTION 'timesheet % was calculated at a locked rate; its amount can no longer change', OLD.id;
    END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER timesheets_calculation_frozen BEFORE UPDATE ON timesheets FOR EACH ROW EXECUTE FUNCTION timesheets_calculation_frozen();

CREATE OR REPLACE FUNCTION timesheet_entries_editable() RETURNS trigger AS $$
DECLARE st text;
BEGIN
    SELECT status INTO st FROM timesheets WHERE id = COALESCE(NEW.timesheet_id, OLD.timesheet_id);
    IF st IS NOT NULL AND st NOT IN ('draft','rejected') THEN
        RAISE EXCEPTION 'entries of a % timesheet cannot change', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER timesheet_entries_editable BEFORE INSERT OR UPDATE OR DELETE ON timesheet_entries FOR EACH ROW EXECUTE FUNCTION timesheet_entries_editable();

CREATE OR REPLACE FUNCTION payment_batch_lines_frozen() RETURNS trigger AS $$
DECLARE st text;
BEGIN
    SELECT status INTO st FROM payment_batches WHERE id = COALESCE(NEW.payment_batch_id, OLD.payment_batch_id);
    IF st IS NOT NULL AND st NOT IN ('draft') THEN
        RAISE EXCEPTION 'lines of a % payment batch cannot change', st;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$ LANGUAGE plpgsql;
CREATE TRIGGER payment_batch_lines_frozen BEFORE INSERT OR UPDATE OR DELETE ON payment_batch_lines FOR EACH ROW EXECUTE FUNCTION payment_batch_lines_frozen();
SQL);
    }

    public function down(): void
    {
        foreach (['staff_memos', 'payment_batch_lines', 'payment_batches', 'timesheet_entries', 'timesheets', 'engagements', 'staff_bank_details', 'staff_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS engagements_locked, timesheets_calculation_frozen, timesheet_entries_editable, payment_batch_lines_frozen CASCADE');
    }
};
