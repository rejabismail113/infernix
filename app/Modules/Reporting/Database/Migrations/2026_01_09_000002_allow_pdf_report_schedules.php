<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled report emails may also deliver PDF (owner request 2026-11-07: every export also as PDF).
 *
 * @see SRS M11-05, M11-06
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE report_schedules DROP CONSTRAINT IF EXISTS report_schedules_format_check');
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN ('csv','xlsx','pdf'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_schedules DROP CONSTRAINT IF EXISTS report_schedules_format_check');
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN ('csv','xlsx'))");
    }
};
