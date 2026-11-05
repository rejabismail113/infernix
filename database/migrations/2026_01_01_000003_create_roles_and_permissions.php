<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roles (OWN FIN PM HR STF AUD APP), permission matrix role × resource × action × record_scope (versioned),
 * explicit record scopes (project/client shares), user invitations.
 *
 * @see SRS G-02, M12-02, §2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8)->unique();
            $table->string('name');
            $table->boolean('mfa_required')->default(false);
            $table->boolean('read_only')->default(false);
            $table->timestampsTz();
        });

        Schema::create('user_roles', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestampTz('granted_at')->useCurrent();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('resource', 64);
            $table->string('action', 16);
            $table->string('record_scope', 16);
            $table->unsignedInteger('policy_version')->default(1);
            $table->timestampTz('effective_from')->useCurrent();
            $table->timestampTz('effective_to')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['role_id', 'resource', 'action', 'policy_version']);
            $table->index(['resource', 'action']);
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_action_check CHECK (action IN ('view','create','edit','approve','pay','export','delete','reveal'))");
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check CHECK (record_scope IN ('all','assigned','own','none'))");

        Schema::create('user_scopes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type', 32); // project client cost_centre
            $table->unsignedBigInteger('scope_id');
            $table->timestampTz('granted_at')->useCurrent();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['user_id', 'scope_type', 'scope_id']);
        });

        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
        Schema::dropIfExists('user_scopes');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
    }
};
