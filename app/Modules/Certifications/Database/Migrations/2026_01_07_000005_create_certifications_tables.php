<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M09 Certifications & memberships with a renewal schedule. A renewal's cost is a LINK to an existing finance
 * transaction/bill/claim (finance_links, purpose renewal) — never a second expense (M09-04).
 *
 * @see SRS M09-01, M09-02, M09-03, M09-04
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('holder_type', 8);
            $table->unsignedBigInteger('staff_profile_id')->nullable()->index();
            $table->string('name');
            $table->string('issuer');
            $table->string('category', 40)->nullable();
            $table->string('credential_no')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('no_expiry')->default(false);
            $table->string('verification_url', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE certifications ADD CONSTRAINT certifications_holder_check CHECK ((holder_type = 'staff' AND staff_profile_id IS NOT NULL) OR (holder_type = 'company' AND staff_profile_id IS NULL))");
        DB::statement('ALTER TABLE certifications ADD CONSTRAINT certifications_expiry_check CHECK ((no_expiry AND expiry_date IS NULL) OR (NOT no_expiry AND expiry_date IS NOT NULL))');
        DB::statement("ALTER TABLE certifications ADD CONSTRAINT certifications_status_check CHECK (status IN ('active','renewal_in_progress','expired','revoked','superseded'))");

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('holder_type', 8);
            $table->unsignedBigInteger('staff_profile_id')->nullable()->index();
            $table->string('organisation');
            $table->string('membership_no')->nullable();
            $table->string('tier')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->boolean('auto_renew')->default(false);
            $table->decimal('annual_fee', 18, 4)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_holder_check CHECK ((holder_type = 'staff' AND staff_profile_id IS NOT NULL) OR (holder_type = 'company' AND staff_profile_id IS NULL))");
        DB::statement('ALTER TABLE memberships ADD CONSTRAINT memberships_period_check CHECK (period_end > period_start)');
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('active','renewal_in_progress','lapsed','cancelled'))");

        Schema::create('renewals', function (Blueprint $table): void {
            $table->id();
            $table->string('renewable_type', 16);
            $table->unsignedBigInteger('renewable_id');
            $table->date('due_date');
            $table->string('status', 16)->default('open');
            $table->date('renewed_on')->nullable();
            $table->date('new_expiry_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['renewable_type', 'renewable_id', 'due_date']);
        });
        DB::statement("ALTER TABLE renewals ADD CONSTRAINT renewals_type_check CHECK (renewable_type IN ('certification','membership'))");
        DB::statement("ALTER TABLE renewals ADD CONSTRAINT renewals_status_check CHECK (status IN ('open','renewed','lapsed','cancelled'))");
    }

    public function down(): void
    {
        foreach (['renewals', 'memberships', 'certifications'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
