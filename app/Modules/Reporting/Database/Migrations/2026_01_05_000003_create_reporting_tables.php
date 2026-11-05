<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M11: metric registry (every dashboard number has a documented definition and security scope) and the
 * Export Centre log (each export is a file in the Document Centre, downloaded via the signed route).
 *
 * @see SRS M11-01, M11-02, M11-04, M11-05, §8.1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_id', 60)->unique();
            $table->string('metric_name');
            $table->text('definition');
            $table->string('owner_role', 8)->default('FIN');
            $table->string('source', 120); // source table(s) / report
            $table->text('calculation_rule');
            $table->string('refresh_time', 40)->default('live (on request)');
            $table->string('security_scope', 60); // permission resource:action required to see it
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });

        Schema::create('exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('report_key', 60);
            $table->string('format', 8);
            $table->jsonb('filters');
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('document_id')->nullable()->constrained('documents');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exports');
        Schema::dropIfExists('report_metrics');
    }
};
