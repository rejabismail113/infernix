<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead form v2 (owner request, 2026-11-07): contact details, next follow-up, wider opportunity types with an
 * "others" description, and a coded lost reason. The coded lists are mirrored by CHECK constraints.
 *
 * @see SRS M02-02, WF-04
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->string('engagement_type_other', 120)->nullable();
            $table->string('source_other', 120)->nullable();
            $table->string('contact_person', 190)->nullable();
            $table->string('contact_email', 190)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->date('next_follow_up_on')->nullable();
            $table->string('lost_reason_code', 32)->nullable();
            $table->index(['company_id', 'next_follow_up_on']);
        });
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_engagement_check CHECK (engagement_type IN ('project','training','consulting','retainer','support','licensing','other'))");
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_lost_reason_check CHECK (lost_reason_code IS NULL OR lost_reason_code IN ('price_budget','competitor','client_cancelled','no_response','postponed','scope_not_suitable','internal_resource','tender_unsuccessful','other'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_lost_reason_check');
        DB::statement('ALTER TABLE leads DROP CONSTRAINT IF EXISTS leads_engagement_check');
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'next_follow_up_on']);
            $table->dropColumn(['engagement_type_other', 'source_other', 'contact_person', 'contact_email', 'contact_phone', 'next_follow_up_on', 'lost_reason_code']);
        });
    }
};
