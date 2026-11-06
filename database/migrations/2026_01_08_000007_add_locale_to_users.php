<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Per-user interface language EN / BM (NFR-12): the identity migration added `locale`; this constrains it. @see SRS NFR-12 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'locale')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('locale', 5)->default('en');
            });
        }
        DB::statement("UPDATE users SET locale = 'en' WHERE locale IS NULL OR locale NOT IN ('en','ms')");
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_locale_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('en','ms'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_locale_check');
    }
};
