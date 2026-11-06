<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backup and restore-test runs (M12-06, NFR-06): encrypted nightly pg_dump to private object storage, retention
 * pruning and periodic restore tests into a scratch database, all recorded for the Backup Status page.
 *
 * @see SRS M12-06, NFR-06, NFR-10
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('status', 10)->default('running');
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->string('object_key')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->text('error')->nullable();
            $table->boolean('pruned')->default(false);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['kind', 'status', 'started_at']);
        });
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_kind_check CHECK (kind IN ('backup','restore_test'))");
        DB::statement("ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_status_check CHECK (status IN ('running','ok','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
