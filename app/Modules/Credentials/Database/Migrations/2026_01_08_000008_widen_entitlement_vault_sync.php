<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: credential_entitlements.vault_sync was varchar(12) but its own default / allowed value 'not_applicable'
 * is 14 characters, so granting access to a credential with no vault adapter failed. Found by the route matrix.
 *
 * @see SRS M13-03
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE credential_entitlements ALTER COLUMN vault_sync TYPE varchar(20)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE credential_entitlements ALTER COLUMN vault_sync TYPE varchar(12)');
    }
};
