<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chart of accounts and the double-entry ledger. The rules that must hold even if application code is
 * wrong are enforced here (CLAUDE.md rule 2):
 *  - each line is exactly one-sided and positive (CHECK);
 *  - a posted journal balances: deferred constraint trigger `journals_balanced` (PostingService also
 *    sets it IMMEDIATE so failures surface inside the posting transaction);
 *  - posted/reversed journals are immutable except the single transition posted → reversed together
 *    with reversed_by_id (nothing else on the row may change); they can never be deleted;
 *  - lines of a posted/reversed journal can never be inserted, updated or deleted;
 *  - a journal can only become posted into an existing, unlocked fiscal period covering its posting date.
 *
 * @see SRS M01-06, M01-08, M01-10, UAT-05, UAT-09, §6.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->string('type', 12);
            $table->string('subtype', 40)->nullable();
            $table->string('system_key', 40)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('chart_accounts')->restrictOnDelete();
            $table->boolean('is_postable')->default(true);
            $table->string('tax_treatment_default', 20)->default('pending_review');
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'system_key']);
        });
        DB::statement("ALTER TABLE chart_accounts ADD CONSTRAINT chart_accounts_type_check CHECK (type IN ('asset','liability','equity','income','expense'))");
        DB::statement("ALTER TABLE chart_accounts ADD CONSTRAINT chart_accounts_tax_check CHECK (tax_treatment_default IN ('pending_review','deductible','non_deductible','capital'))");

        Schema::create('journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('journal_no', 32)->unique();
            $table->date('journal_date');
            $table->date('posting_date');
            $table->foreignId('fiscal_period_id')->constrained()->restrictOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('status', 12)->default('draft');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('journals')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journals')->restrictOnDelete();
            $table->text('memo')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();
            $table->index(['fiscal_period_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index('posting_date');
        });
        DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_status_check CHECK (status IN ('draft','posted','reversed'))");

        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('account_id')->constrained('chart_accounts')->restrictOnDelete();
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->char('currency', 3)->default('MYR');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->foreignId('cost_centre_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->text('description')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['journal_id', 'line_no']);
            $table->index(['account_id', 'journal_id']);
            $table->index('project_id');
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_sided CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_currency_check CHECK (currency = 'MYR')");

        DB::unprepared(<<<'SQL'
-- Posted journals are immutable; the ONLY allowed change is posted → reversed + reversed_by_id.
CREATE OR REPLACE FUNCTION journals_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'posted journal % cannot be deleted; reverse it instead (M01-06)', OLD.journal_no;
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status = 'reversed' THEN
        RAISE EXCEPTION 'reversed journal % is immutable', OLD.journal_no;
    END IF;
    IF OLD.status = 'posted' THEN
        IF NOT (NEW.status = 'reversed' AND OLD.reversed_by_id IS NULL AND NEW.reversed_by_id IS NOT NULL
                AND (to_jsonb(NEW) - 'status' - 'reversed_by_id') = (to_jsonb(OLD) - 'status' - 'reversed_by_id')) THEN
            RAISE EXCEPTION 'posted journal % is immutable: only status posted→reversed with reversed_by_id may change', OLD.journal_no;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER journals_immutable BEFORE UPDATE OR DELETE ON journals FOR EACH ROW EXECUTE FUNCTION journals_immutable();

-- Lines of a posted/reversed journal are frozen.
CREATE OR REPLACE FUNCTION journal_lines_immutable() RETURNS trigger AS $$
DECLARE parent_status text;
BEGIN
    SELECT status INTO parent_status FROM journals WHERE id = COALESCE(NEW.journal_id, OLD.journal_id);
    IF parent_status IN ('posted','reversed') THEN
        RAISE EXCEPTION 'lines of a posted journal cannot be % (M01-06)', lower(TG_OP);
    END IF;
    IF TG_OP = 'UPDATE' AND NEW.journal_id <> OLD.journal_id THEN
        RAISE EXCEPTION 'journal lines cannot move between journals';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER journal_lines_immutable BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_lines_immutable();

-- Posting requires an existing, unlocked period that covers the posting date.
CREATE OR REPLACE FUNCTION journals_period_open() RETURNS trigger AS $$
DECLARE p record;
BEGIN
    IF NEW.status = 'posted' AND (TG_OP = 'INSERT' OR OLD.status = 'draft') THEN
        SELECT * INTO p FROM fiscal_periods WHERE id = NEW.fiscal_period_id;
        IF p IS NULL OR NEW.posting_date < p.starts_on OR NEW.posting_date > p.ends_on THEN
            RAISE EXCEPTION 'posting date % is outside fiscal period %', NEW.posting_date, NEW.fiscal_period_id;
        END IF;
        IF p.locked_at IS NOT NULL THEN
            RAISE EXCEPTION 'fiscal period % is locked: post a reversal or adjustment in an open period (M01-08)', NEW.fiscal_period_id;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER journals_period_open BEFORE INSERT OR UPDATE ON journals FOR EACH ROW EXECUTE FUNCTION journals_period_open();

-- Debits equal credits for every posted journal (deferred; checked at commit unless set IMMEDIATE).
CREATE OR REPLACE FUNCTION journals_balanced() RETURNS trigger AS $$
DECLARE d numeric; c numeric; n integer;
BEGIN
    IF NEW.status IN ('posted','reversed') THEN
        SELECT COALESCE(sum(debit),0), COALESCE(sum(credit),0), count(*) INTO d, c, n FROM journal_lines WHERE journal_id = NEW.id;
        IF n < 2 OR d <> c OR d = 0 THEN
            RAISE EXCEPTION 'journal % is not balanced (debit %, credit %, % lines) (UAT-05)', NEW.journal_no, d, c, n;
        END IF;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE CONSTRAINT TRIGGER journals_balanced AFTER INSERT OR UPDATE ON journals
    DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION journals_balanced();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        DB::unprepared('DROP FUNCTION IF EXISTS journals_immutable() CASCADE; DROP FUNCTION IF EXISTS journal_lines_immutable() CASCADE; DROP FUNCTION IF EXISTS journals_period_open() CASCADE; DROP FUNCTION IF EXISTS journals_balanced() CASCADE;');
        Schema::dropIfExists('chart_accounts');
    }
};
