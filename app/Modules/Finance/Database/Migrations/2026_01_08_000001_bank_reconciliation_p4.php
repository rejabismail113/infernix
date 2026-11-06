<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4 bank reconciliation: configurable import templates, rule-based auto-matching, and split matches
 * (many bank lines → one ledger line, one bank line → many ledger lines). A ceiling trigger guarantees no
 * bank line and no ledger line is ever matched beyond its own amount.
 *
 * @see SRS M01-07, WF-08
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_import_profiles', function (Blueprint $table): void {
            $table->string('template_key', 40)->nullable()->unique();
            $table->string('bank_name', 60)->nullable();
            $table->char('delimiter', 1)->default(',');
            $table->unsignedSmallInteger('skip_rows')->default(0);
        });

        DB::statement('ALTER TABLE bank_matches DROP CONSTRAINT IF EXISTS bank_matches_bank_transaction_id_unique');
        DB::statement('ALTER TABLE bank_matches DROP CONSTRAINT IF EXISTS bank_matches_journal_line_id_unique');
        Schema::table('bank_matches', function (Blueprint $table): void {
            $table->uuid('match_group')->nullable()->index();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unique(['bank_transaction_id', 'journal_line_id']);
            $table->index('journal_line_id');
        });
        DB::statement('ALTER TABLE bank_matches ADD CONSTRAINT bank_matches_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE bank_transactions DROP CONSTRAINT bank_transactions_status_check');
        DB::statement("ALTER TABLE bank_transactions ADD CONSTRAINT bank_transactions_status_check CHECK (status IN ('unmatched','partial','matched','excluded'))");

        Schema::create('bank_match_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->string('direction', 4)->default('any');
            $table->unsignedSmallInteger('date_window_days')->default(3);
            $table->string('reference_pattern', 200)->nullable();
            $table->string('payee_alias', 120)->nullable();
            $table->string('ledger_keyword', 120)->nullable();
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE bank_match_rules ADD CONSTRAINT bank_match_rules_direction_check CHECK (direction IN ('any','in','out'))");
        DB::statement('ALTER TABLE bank_match_rules ADD CONSTRAINT bank_match_rules_window_check CHECK (date_window_days <= 60)');

        DB::unprepared(<<<'SQL'
-- A bank line can never be matched beyond its own amount, nor a ledger line beyond its own (M01-07).
CREATE OR REPLACE FUNCTION bank_matches_ceiling() RETURNS trigger AS $$
DECLARE bank_amt numeric; line_amt numeric; used numeric;
BEGIN
    SELECT abs(amount) INTO bank_amt FROM bank_transactions WHERE id = NEW.bank_transaction_id FOR UPDATE;
    SELECT debit + credit INTO line_amt FROM journal_lines WHERE id = NEW.journal_line_id;
    SELECT COALESCE(sum(amount), 0) INTO used FROM bank_matches WHERE bank_transaction_id = NEW.bank_transaction_id AND id IS DISTINCT FROM NEW.id;
    IF used + NEW.amount > bank_amt THEN
        RAISE EXCEPTION 'bank line % over-matched: % + % > %', NEW.bank_transaction_id, used, NEW.amount, bank_amt;
    END IF;
    SELECT COALESCE(sum(amount), 0) INTO used FROM bank_matches WHERE journal_line_id = NEW.journal_line_id AND id IS DISTINCT FROM NEW.id;
    IF used + NEW.amount > line_amt THEN
        RAISE EXCEPTION 'ledger line % over-matched: % + % > %', NEW.journal_line_id, used, NEW.amount, line_amt;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER bank_matches_ceiling BEFORE INSERT OR UPDATE ON bank_matches FOR EACH ROW EXECUTE FUNCTION bank_matches_ceiling();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS bank_matches_ceiling() CASCADE;');
        Schema::dropIfExists('bank_match_rules');
    }
};
