<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notification rules: event → roles/users → channel → lead days (G-05 groundwork).
 *
 * @see SRS G-05, M09-02, M10-05
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 64);
            $table->jsonb('target_roles')->nullable();
            $table->jsonb('target_user_ids')->nullable();
            $table->string('channel', 8)->default('both');
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['event', 'lead_days']);
        });
        DB::statement("ALTER TABLE notification_rules ADD CONSTRAINT notification_rules_channel_check CHECK (channel IN ('email','in_app','both'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
