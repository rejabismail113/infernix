<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned approval matrices (amount thresholds → required approver roles per level) and idempotency keys
 * for replay-safe payment/receipt/invoice endpoints.
 *
 * @see SRS M12-03, M01-03, §2.1, §5.1, UAT-11
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_matrices', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedInteger('version');
            $table->timestampTz('effective_from')->useCurrent();
            $table->timestampTz('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['entity_type', 'version']);
        });

        Schema::create('approval_matrix_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_matrix_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_amount', 18, 4)->default(0);
            $table->decimal('max_amount', 18, 4)->nullable();
            $table->unsignedSmallInteger('level');
            $table->jsonb('approver_roles');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE approval_matrix_rules ADD CONSTRAINT approval_matrix_rules_range_check CHECK (max_amount IS NULL OR max_amount >= min_amount)');

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->string('route', 120);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_location')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'key', 'route']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('approval_matrix_rules');
        Schema::dropIfExists('approval_matrices');
    }
};
