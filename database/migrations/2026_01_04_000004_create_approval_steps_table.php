<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic approval history for bills, payments and adjusting journals (claims keep claim_approvals).
 * Lets ApprovalService refuse the same person approving two levels of one item.
 *
 * @see SRS M12-03, §2.1, UAT-11
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedSmallInteger('level');
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegation_id')->nullable()->constrained('approval_delegations')->restrictOnDelete();
            $table->boolean('owner_override')->default(false);
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at')->useCurrent();
            $table->unique(['entity_type', 'entity_id', 'level']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_steps');
    }
};
