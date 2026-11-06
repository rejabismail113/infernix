<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M08 CSR programmes. Cash spend is read from finance records linked to the programme's activity (M08-02);
 * in-kind contributions are a separate table and never enter cash totals (M08-03). Evidence photos carry a
 * consent flag and are stored as restricted documents (M08-04).
 *
 * @see SRS M08-01, M08-02, M08-03, M08-04
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csr_programmes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('programme_no', 32)->unique();
            $table->string('title');
            $table->text('objectives')->nullable();
            $table->string('partner')->nullable();
            $table->string('sponsor')->nullable();
            $table->string('venue')->nullable();
            $table->date('programme_date');
            $table->string('beneficiary_group')->nullable();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('actual_count')->nullable();
            $table->unsignedBigInteger('activity_id')->nullable()->unique();
            $table->string('status', 16)->default('planned');
            $table->text('outcome')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE csr_programmes ADD CONSTRAINT csr_programmes_status_check CHECK (status IN ('planned','ongoing','completed','cancelled'))");

        Schema::create('csr_volunteers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('programme_id')->constrained('csr_programmes')->cascadeOnDelete();
            $table->unsignedBigInteger('staff_profile_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('hours', 8, 2)->default(0);
            $table->string('role', 60)->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE csr_volunteers ADD CONSTRAINT csr_volunteers_who_check CHECK (staff_profile_id IS NOT NULL OR name IS NOT NULL)');

        Schema::create('csr_in_kind_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('programme_id')->constrained('csr_programmes')->cascadeOnDelete();
            $table->string('item');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('estimated_value', 18, 4)->default(0);
            $table->string('source');
            $table->string('valuation_basis')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE csr_in_kind_contributions ADD CONSTRAINT csr_in_kind_value_check CHECK (estimated_value >= 0 AND quantity > 0)');

        Schema::create('csr_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('programme_id')->constrained('csr_programmes')->cascadeOnDelete();
            $table->unsignedBigInteger('document_id')->unique();
            $table->boolean('people_identifiable')->default(true);
            $table->boolean('consent_obtained')->default(false);
            $table->text('consent_note')->nullable();
            $table->boolean('publishable')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        // Identifiable people may only be marked publishable with consent recorded (PDPA, M08-04).
        DB::statement('ALTER TABLE csr_evidence ADD CONSTRAINT csr_evidence_consent_check CHECK (NOT publishable OR NOT people_identifiable OR consent_obtained)');
    }

    public function down(): void
    {
        foreach (['csr_evidence', 'csr_in_kind_contributions', 'csr_volunteers', 'csr_programmes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
