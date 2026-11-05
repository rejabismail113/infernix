<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable dictionaries (expense/income categories, project types, tax codes, cost centres, status
 * labels …) and claim types with versioned rates. Seeded values are PLACEHOLDER (notes column).
 *
 * @see SRS G-09, M12-05, M01-04, §2.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionaries', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->boolean('system')->default(false);
            $table->timestampsTz();
        });

        Schema::create('dictionary_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('dictionary_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('label_en');
            $table->string('label_ms')->nullable();
            $table->jsonb('meta')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('dictionary_items')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
            $table->unique(['dictionary_id', 'code', 'effective_from']);
        });
        DB::statement('ALTER TABLE dictionary_items ADD CONSTRAINT dictionary_items_effective_check CHECK (effective_to IS NULL OR effective_to > effective_from)');

        Schema::create('claim_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('receipt_required')->default(true);
            $table->boolean('is_medical')->default(false);
            $table->foreignId('default_expense_category_id')->nullable()->constrained('dictionary_items')->nullOnDelete();
            $table->decimal('per_claim_limit', 18, 4)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('claim_type_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('claim_type_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 18, 4);
            $table->string('unit', 16); // km day session
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version');
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['claim_type_id', 'version']);
        });
        DB::statement('ALTER TABLE claim_type_rates ADD CONSTRAINT claim_type_rates_effective_check CHECK (effective_to IS NULL OR effective_to > effective_from)');
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_type_rates');
        Schema::dropIfExists('claim_types');
        Schema::dropIfExists('dictionary_items');
        Schema::dropIfExists('dictionaries');
    }
};
