<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications (Laravel database channel) and a dispatch log so scheduled reminders are sent once per
 * rule + subject + recipient (callers add a date to the key for daily reminders).
 *
 * @see SRS G-05
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->jsonb('data');
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('notification_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key', 160);
            $table->date('dispatched_on');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['notification_rule_id', 'user_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
        Schema::dropIfExists('notifications');
    }
};
