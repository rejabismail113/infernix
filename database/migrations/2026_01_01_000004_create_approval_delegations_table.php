<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delegated approvals: versioned, never edited in place (a change inserts a new version that supersedes).
 *
 * @see SRS M12-03, §2.1, §2 (APP)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->restrictOnDelete();
            $table->string('entity_type', 32); // claim payment invoice bill purchase_request credit_note asset_disposal
            $table->decimal('max_amount', 18, 4)->nullable();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_to');
            $table->text('reason');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('approval_delegations')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['delegate_id', 'entity_type', 'valid_from', 'valid_to']);
        });
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_window_check CHECK (valid_to > valid_from)');
        DB::statement('ALTER TABLE approval_delegations ADD CONSTRAINT approval_delegations_self_check CHECK (delegator_id <> delegate_id)');

        // Versioned: business columns are frozen once written (only revoked_at / updated_at may change).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION approval_delegations_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'approval_delegations are append-only; create a new version instead';
    END IF;
    IF NEW.delegator_id <> OLD.delegator_id OR NEW.delegate_id <> OLD.delegate_id
       OR NEW.entity_type <> OLD.entity_type OR NEW.max_amount IS DISTINCT FROM OLD.max_amount
       OR NEW.valid_from <> OLD.valid_from OR NEW.valid_to <> OLD.valid_to OR NEW.reason <> OLD.reason
       OR NEW.version <> OLD.version OR NEW.supersedes_id IS DISTINCT FROM OLD.supersedes_id
       OR NEW.created_by <> OLD.created_by OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'approval_delegations are versioned and never edited in place';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER approval_delegations_immutable BEFORE UPDATE OR DELETE ON approval_delegations
    FOR EACH ROW EXECUTE FUNCTION approval_delegations_immutable();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
        DB::unprepared('DROP FUNCTION IF EXISTS approval_delegations_immutable()');
    }
};
