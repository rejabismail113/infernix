<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional MyInvois / e-Invoice submission (behind features.myinvois): an outbox with ONE row per invoice
 * (replay-safe, idempotency key derived from the invoice id), retry with exponential backoff, and an
 * append-only event log. Eligibility is NOT assumed — the accountant confirms before the flag is switched on.
 *
 * @see SRS §6.2, M03-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoice_outbox', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->string('idempotency_key', 80)->unique();
            $table->string('gateway', 24);
            $table->jsonb('payload');
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('external_uuid', 80)->nullable();
            $table->string('long_id', 120)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'next_attempt_at']);
        });
        DB::statement("ALTER TABLE einvoice_outbox ADD CONSTRAINT einvoice_outbox_status_check CHECK (status IN ('pending','submitting','accepted','rejected','failed'))");

        Schema::create('einvoice_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbox_id')->constrained('einvoice_outbox')->restrictOnDelete();
            $table->string('event', 24);
            $table->jsonb('detail')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION einvoice_events_append_only() RETURNS trigger AS $$
BEGIN RAISE EXCEPTION 'einvoice_events is append-only'; END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER einvoice_events_append_only BEFORE UPDATE OR DELETE ON einvoice_events FOR EACH ROW EXECUTE FUNCTION einvoice_events_append_only();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_events');
        Schema::dropIfExists('einvoice_outbox');
        DB::unprepared('DROP FUNCTION IF EXISTS einvoice_events_append_only() CASCADE;');
    }
};
