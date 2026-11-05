<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal CRM client master and a project stub so an accepted quotation can be converted (M03-02, UAT-06).
 * Prompt 4 (M02) extends `projects` with NEW migrations only (members, milestones, training…).
 *
 * @see SRS M02-01, M03-02, WF-04, UAT-06
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('registration_no', 60)->nullable();
            $table->string('tax_no', 60)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('contact_person')->nullable();
            $table->jsonb('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['company_id', 'name']);
        });
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('active','inactive'))");

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40)->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('engagement_type', 16)->default('project');
            $table->text('scope')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('contract_value', 18, 4)->default(0);
            $table->string('status_delivery', 16)->default('active');
            $table->string('status_payment', 20)->default('unbilled');
            // FK to commercial_documents is added by the Commercial migration (it runs after this one).
            $table->unsignedBigInteger('source_quotation_id')->nullable()->unique();
            $table->foreignId('pm_user_id')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_engagement_check CHECK (engagement_type IN ('project','training','retainer'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_delivery_check CHECK (status_delivery IN ('lead','active','on_hold','completed','closed'))");
        DB::statement("ALTER TABLE projects ADD CONSTRAINT projects_payment_check CHECK (status_payment IN ('unbilled','partially_billed','billed','partially_paid','paid'))");
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_contract_value_check CHECK (contract_value >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
        Schema::dropIfExists('clients');
    }
};
