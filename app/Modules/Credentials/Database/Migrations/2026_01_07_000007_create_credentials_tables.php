<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13 Credentials & secrets: METADATA ONLY. There is deliberately no column that can hold a password, API key or
 * token — the secret lives in the external vault and this table keeps a vault item reference
 * (tests/Architecture/NoSecretColumnsTest.php asserts the column list). Access is request → resource-owner
 * approval → entitlement → revoke (WF-07); a leaver's entitlements are revoked automatically (M13-04).
 *
 * @see SRS M13-01, M13-02, M13-03, M13-04, M13-06, WF-07, UAT-08
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('credential_no', 32)->unique();
            $table->string('system_name');
            $table->string('category', 16);
            $table->string('environment', 12)->default('production');
            $table->string('url', 500)->nullable();
            $table->string('username_reference')->nullable();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('vault_provider', 16)->default('none');
            $table->string('vault_item_reference')->nullable();
            $table->string('sensitivity', 10)->default('high');
            $table->unsignedSmallInteger('rotation_interval_days')->nullable();
            $table->date('last_rotated_on')->nullable();
            $table->date('rotation_due')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 12)->default('active');
            $table->boolean('is_shared_service_account')->default(false);
            $table->text('shared_justification')->nullable();
            $table->foreignId('shared_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'rotation_due']);
        });
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_category_check CHECK (category IN ('cloud','bi','database','development','ai','corporate','client','api'))");
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_env_check CHECK (environment IN ('production','staging','development','shared'))");
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_provider_check CHECK (vault_provider IN ('none','bitwarden','onepassword','other'))");
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_sensitivity_check CHECK (sensitivity IN ('low','medium','high','critical'))");
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_status_check CHECK (status IN ('active','rotating','revoked','retired'))");
        // Client credentials must be segregated to a client (M13-06); shared service accounts need a justification.
        DB::statement("ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_client_scope_check CHECK (category <> 'client' OR client_id IS NOT NULL)");
        DB::statement('ALTER TABLE credentials_metadata ADD CONSTRAINT credentials_shared_check CHECK (NOT is_shared_service_account OR shared_justification IS NOT NULL)');

        Schema::create('credential_access_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('credentials_metadata')->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->text('justification');
            $table->date('access_until')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestampsTz();
            $table->index(['credential_id', 'status']);
        });
        DB::statement("ALTER TABLE credential_access_requests ADD CONSTRAINT credential_requests_status_check CHECK (status IN ('pending','approved','rejected','withdrawn'))");
        DB::statement('ALTER TABLE credential_access_requests ADD CONSTRAINT credential_requests_maker_checker CHECK (decided_by IS NULL OR decided_by <> requester_id)');

        Schema::create('credential_entitlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('credentials_metadata')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('request_id')->nullable()->constrained('credential_access_requests')->restrictOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->date('expires_on')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->string('vault_sync', 12)->default('not_applicable');
        });
        DB::statement('CREATE UNIQUE INDEX credential_entitlements_one_active ON credential_entitlements (credential_id, user_id) WHERE revoked_at IS NULL');
        DB::statement("ALTER TABLE credential_entitlements ADD CONSTRAINT credential_entitlements_sync_check CHECK (vault_sync IN ('not_applicable','synced','pending','failed'))");

        Schema::create('credential_rotations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->constrained('credentials_metadata')->restrictOnDelete();
            $table->date('rotated_on');
            $table->foreignId('rotated_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('jml_checklists', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 8);
            $table->unsignedBigInteger('staff_profile_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('trigger', 60);
            $table->jsonb('items');
            $table->string('status', 12)->default('open');
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE jml_checklists ADD CONSTRAINT jml_checklists_kind_check CHECK (kind IN ('joiner','mover','leaver'))");
        DB::statement("ALTER TABLE jml_checklists ADD CONSTRAINT jml_checklists_status_check CHECK (status IN ('open','completed'))");
    }

    public function down(): void
    {
        foreach (['jml_checklists', 'credential_rotations', 'credential_entitlements', 'credential_access_requests', 'credentials_metadata'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
