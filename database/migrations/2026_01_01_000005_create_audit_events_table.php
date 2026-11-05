<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central append-only audit trail. A trigger RAISEs on any UPDATE or DELETE (migrations and the app
 * usually share a DB user, so a GRANT-based rule would not hold).
 *
 * @see SRS G-06, M12-04, NFR-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_role', 64)->nullable();
            $table->string('action', 48);
            $table->string('entity_type', 96);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('redactions')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('request_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index('request_id');
            $table->index(['action', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION audit_events_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'audit_events is append-only (% not allowed)', TG_OP;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER audit_events_append_only BEFORE UPDATE OR DELETE ON audit_events
    FOR EACH ROW EXECUTE FUNCTION audit_events_append_only();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_events_append_only()');
    }
};
