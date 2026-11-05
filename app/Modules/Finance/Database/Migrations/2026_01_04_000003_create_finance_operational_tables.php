<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank accounts, income/expense transactions, claims, bills, payments and allocations.
 * Allocation ceiling (no target can be paid beyond its balance, M10-03, §6.3) is enforced by a trigger
 * that locks the target row, in addition to the service check.
 *
 * @see SRS M01-01 … M01-05, M01-07, M01-09, M01-10, M10-03, WF-01, WF-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_bank_account_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('gl_account_id')->unique()->constrained('chart_accounts')->restrictOnDelete();
            $table->decimal('opening_balance', 18, 4)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestampsTz();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('transaction_no', 32)->unique();
            $table->string('direction', 8);
            $table->string('inflow_type', 24)->nullable();
            $table->string('payment_source', 32)->nullable();
            $table->string('capitalization_class', 24)->nullable();
            $table->date('transaction_date');
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->string('payment_method', 24)->nullable();
            $table->string('bank_reference', 120)->nullable();
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('counterparty_name')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->string('tax_treatment', 20)->default('pending_review');
            $table->string('status', 12)->default('draft');
            $table->text('description')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->index(['direction', 'transaction_date']);
            $table->index('status');
        });
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_direction_check CHECK (direction IN ('inflow','outflow'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_inflow_check CHECK (inflow_type IS NULL OR inflow_type IN ('sales_project','sales_training','grant','other_income','capital_injection','loan','refund'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_source_check CHECK (payment_source IS NULL OR payment_source IN ('DIRECT_COMPANY','STAFF_PAID_REIMBURSABLE','OWNER_PAID'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_class_check CHECK (capitalization_class IS NULL OR capitalization_class IN ('CAPITAL_ASSET','LOW_VALUE_EQUIPMENT','CONSUMABLE','EXPENSE','OWNER_DRAWING'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_drawing_check CHECK (capitalization_class IS DISTINCT FROM 'OWNER_DRAWING' OR payment_source = 'DIRECT_COMPANY')");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_shape_check CHECK ((direction = 'inflow' AND inflow_type IS NOT NULL AND payment_source IS NULL) OR (direction = 'outflow' AND inflow_type IS NULL AND payment_source IS NOT NULL AND capitalization_class IS NOT NULL))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_tax_check CHECK (tax_treatment IN ('pending_review','deductible','non_deductible','capital'))");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN ('draft','posted','reversed','cancelled'))");

        Schema::create('transaction_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->text('description');
            $table->decimal('amount', 18, 4);
            $table->foreignId('category_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->foreignId('cost_centre_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unique(['transaction_id', 'line_no']);
        });
        DB::statement('ALTER TABLE transaction_lines ADD CONSTRAINT transaction_lines_amount_check CHECK (amount > 0)');

        Schema::create('claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('claim_no', 32)->unique();
            $table->foreignId('staff_user_id')->constrained('users')->restrictOnDelete();
            $table->text('purpose');
            $table->foreignId('cost_centre_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('approved_amount', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['staff_user_id', 'status']);
        });
        DB::statement("ALTER TABLE claims ADD CONSTRAINT claims_status_check CHECK (status IN ('draft','submitted','pending_approval','approved','rejected','finance_payable','paid','closed','cancelled'))");
        DB::statement('ALTER TABLE claims ADD CONSTRAINT claims_paid_check CHECK (paid_amount >= 0 AND paid_amount <= approved_amount)');

        Schema::create('claim_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('claim_type_id')->constrained()->restrictOnDelete();
            $table->date('incurred_on');
            $table->text('description');
            $table->decimal('amount', 18, 4);
            $table->foreignId('category_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->string('status', 12)->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->decimal('rate_snapshot', 18, 4)->nullable();
            $table->foreignId('rate_version_id')->nullable()->constrained('claim_type_rates')->restrictOnDelete();
            $table->text('clinical_note')->nullable(); // encrypted; HR/OWN/claimant only
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->timestampsTz();
            $table->unique(['claim_id', 'line_no']);
        });
        DB::statement('ALTER TABLE claim_lines ADD CONSTRAINT claim_lines_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE claim_lines ADD CONSTRAINT claim_lines_status_check CHECK (status IN ('pending','approved','rejected'))");

        Schema::create('claim_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 10);
            $table->foreignId('delegation_id')->nullable()->constrained('approval_delegations')->restrictOnDelete();
            $table->boolean('owner_override')->default(false);
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at')->useCurrent();
        });
        DB::statement("ALTER TABLE claim_approvals ADD CONSTRAINT claim_approvals_decision_check CHECK (decision IN ('approved','rejected'))");

        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('bill_no', 32)->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('vendor_invoice_no', 64);
            $table->date('bill_date');
            $table->date('due_date');
            $table->decimal('total_amount', 18, 4)->default(0);
            $table->decimal('paid_amount', 18, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->string('tax_treatment', 20)->default('pending_review');
            $table->text('description')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['vendor_id', 'vendor_invoice_no']);
            $table->index(['status', 'due_date']);
        });
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('draft','pending_approval','approved','partially_paid','paid','cancelled'))");
        DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_paid_check CHECK (paid_amount >= 0 AND paid_amount <= total_amount)');
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_tax_check CHECK (tax_treatment IN ('pending_review','deductible','non_deductible','capital'))");

        Schema::create('bill_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_price', 18, 4);
            $table->decimal('amount', 18, 4);
            $table->foreignId('category_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->string('capitalization_class', 24)->default('EXPENSE');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unique(['bill_id', 'line_no']);
        });
        DB::statement('ALTER TABLE bill_lines ADD CONSTRAINT bill_lines_amount_check CHECK (amount > 0 AND quantity > 0)');
        DB::statement("ALTER TABLE bill_lines ADD CONSTRAINT bill_lines_class_check CHECK (capitalization_class IN ('CAPITAL_ASSET','LOW_VALUE_EQUIPMENT','CONSUMABLE','EXPENSE'))");

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('payment_no', 32)->unique();
            $table->date('payment_date');
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->string('method', 16);
            $table->string('reference', 120)->nullable();
            $table->string('payee_type', 12);
            $table->unsignedBigInteger('payee_id')->nullable();
            $table->string('payee_name');
            $table->decimal('amount', 18, 4);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->foreignId('journal_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('voucher_document_id')->nullable();
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->boolean('owner_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->index('status');
        });
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('draft','pending_approval','approved','paid','cancelled'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_payee_check CHECK (payee_type IN ('vendor','staff','owner','other'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('transfer','duitnow','cheque','cash','card'))");

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_id');
            $table->decimal('amount', 18, 4);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['payment_id', 'target_type', 'target_id']);
            $table->index(['target_type', 'target_id']);
        });
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_type_check CHECK (target_type IN ('bill','claim','invoice_refund'))");

        DB::unprepared(<<<'SQL'
-- No target may be allocated beyond its balance across all non-cancelled payments (M10-03, §6.3).
CREATE OR REPLACE FUNCTION payment_allocations_ceiling() RETURNS trigger AS $$
DECLARE ceiling numeric; target_status text; already numeric;
BEGIN
    IF NEW.target_type = 'bill' THEN
        SELECT total_amount, status INTO ceiling, target_status FROM bills WHERE id = NEW.target_id FOR UPDATE;
        IF target_status NOT IN ('approved','partially_paid') THEN
            RAISE EXCEPTION 'bill % is not payable (status %)', NEW.target_id, target_status;
        END IF;
    ELSIF NEW.target_type = 'claim' THEN
        SELECT approved_amount, status INTO ceiling, target_status FROM claims WHERE id = NEW.target_id FOR UPDATE;
        IF target_status NOT IN ('finance_payable') THEN
            RAISE EXCEPTION 'claim % is not payable (status %)', NEW.target_id, target_status;
        END IF;
    ELSE
        RAISE EXCEPTION 'allocations to % are not supported yet', NEW.target_type;
    END IF;
    IF ceiling IS NULL THEN
        RAISE EXCEPTION '% % does not exist', NEW.target_type, NEW.target_id;
    END IF;
    SELECT COALESCE(sum(a.amount), 0) INTO already FROM payment_allocations a JOIN payments p ON p.id = a.payment_id
        WHERE a.target_type = NEW.target_type AND a.target_id = NEW.target_id AND p.status <> 'cancelled' AND a.id IS DISTINCT FROM NEW.id;
    IF already + NEW.amount > ceiling THEN
        RAISE EXCEPTION 'allocation exceeds the % balance: % already allocated of %, % requested (M10-03)', NEW.target_type, already, ceiling, NEW.amount;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER payment_allocations_ceiling BEFORE INSERT OR UPDATE ON payment_allocations FOR EACH ROW EXECUTE FUNCTION payment_allocations_ceiling();
SQL);

        Schema::create('bank_import_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->jsonb('column_mapping');
            $table->string('date_format', 20)->default('d/m/Y');
            $table->boolean('has_header')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_profile_id')->nullable()->constrained('bank_import_profiles')->restrictOnDelete();
            $table->uuid('import_batch_id')->nullable();
            $table->unsignedBigInteger('statement_document_id')->nullable();
            $table->date('txn_date');
            $table->text('description');
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 18, 4);
            $table->decimal('running_balance', 18, 4)->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 12)->default('unmatched');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['bank_account_id', 'fingerprint']);
            $table->index(['bank_account_id', 'txn_date']);
        });
        DB::statement('ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_amount_check CHECK (amount <> 0)');
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_status_check CHECK (status IN ('unmatched','matched','excluded'))");

        Schema::create('bank_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('journal_line_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->string('match_type', 12)->default('manual');
            $table->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('matched_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['bank_matches', 'bank_transactions', 'bank_import_profiles', 'payment_allocations', 'payments', 'bill_lines', 'bills', 'claim_approvals', 'claim_lines', 'claims', 'transaction_lines', 'transactions', 'bank_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS payment_allocations_ceiling() CASCADE;');
    }
};
