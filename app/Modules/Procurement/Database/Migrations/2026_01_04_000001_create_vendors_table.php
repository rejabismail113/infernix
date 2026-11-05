<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal vendor master for bills/payments (M01-05). Prompt 5 (M10) extends it with NEW migrations only.
 *
 * @see SRS M10-01, M10-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('registration_no')->nullable();
            $table->string('tax_no')->nullable();
            $table->jsonb('contacts')->nullable();
            $table->text('bank_reference')->nullable(); // encrypted cast, masked
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->index('name');
        });
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_status_check CHECK (status IN ('active','inactive'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
