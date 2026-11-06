<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled report emails (M11): each run exports AS THE RECIPIENT (their own row scope and permissions are
 * re-checked every time) and emails a link to the stored, policy-checked download — never an attachment.
 *
 * @see SRS M11-05, G-05, NFR-02
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('report_key', 80);
            $table->string('format', 4)->default('xlsx');
            $table->string('frequency', 8);
            $table->unsignedSmallInteger('day')->nullable();
            $table->jsonb('recipient_ids');
            $table->jsonb('filters')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampTz('last_run_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_freq_check CHECK (frequency IN ('daily','weekly','monthly'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN ('csv','xlsx'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
