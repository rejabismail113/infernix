<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal years and monthly periods with lock/unlock (audited, OWN/FIN only).
 *
 * @see SRS G-03, M01-08, M12-05, UAT-09
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestampsTz();
            $table->unique(['company_id', 'year']);
        });
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_status_check CHECK (status IN ('open','closed'))");
        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_range_check CHECK (ends_on > starts_on)');

        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_no');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampTz('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lock_reason')->nullable();
            $table->timestampsTz();
            $table->unique(['fiscal_year_id', 'period_no']);
        });
        DB::statement('ALTER TABLE fiscal_periods ADD CONSTRAINT fiscal_periods_range_check CHECK (ends_on >= starts_on)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
