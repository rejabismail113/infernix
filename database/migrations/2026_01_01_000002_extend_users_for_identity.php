<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity extensions: MFA, deactivation, AUD time window, locale.
 *
 * @see SRS G-01, M12-01, NFR-01
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->text('mfa_secret')->nullable();            // encrypted cast, never serialised
            $table->text('mfa_recovery_codes')->nullable();    // encrypted cast (json)
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('access_from')->nullable();    // AUD time-boxed window
            $table->timestampTz('access_until')->nullable();
            $table->string('locale', 5)->default('en');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('staff_profile_id')->nullable(); // FK added by M04
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['mfa_secret', 'mfa_recovery_codes', 'mfa_confirmed_at', 'last_login_at', 'deactivated_at', 'access_from', 'access_until', 'locale', 'staff_profile_id']);
        });
    }
};
