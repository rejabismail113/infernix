<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M02 Projects & Training (Phase P2) — extends the Prompt 3 stub with NEW tables/columns only: lead pipeline,
 * milestones/deliverables, tasks, team (with the rate agreement version used), training sessions + participants,
 * closeout checklist. Delivery status stays separate from payment status (M02-04).
 *
 * @see SRS M02-01 … M02-07, WF-04
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('prospect_name')->nullable(); // before the prospect becomes a client
            $table->string('title');
            $table->string('stage', 12)->default('new');
            $table->string('engagement_type', 16)->default('project');
            $table->decimal('estimated_value', 18, 4)->default(0);
            $table->date('expected_close')->nullable();
            $table->string('source', 60)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('owner_user_id')->constrained('users');
            $table->unsignedBigInteger('quotation_id')->nullable()->unique(); // ⚑ commercial_documents
            $table->text('lost_reason')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_stage_check CHECK (stage IN ('new','qualified','proposal','negotiation','won','lost'))");
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_party_check CHECK (client_id IS NOT NULL OR prospect_name IS NOT NULL)');
        DB::statement('ALTER TABLE leads ADD CONSTRAINT leads_quotation_fk FOREIGN KEY (quotation_id) REFERENCES commercial_documents(id)');

        Schema::table('projects', function (Blueprint $table): void {
            $table->text('billing_terms')->nullable();
            $table->decimal('cost_budget', 18, 4)->nullable();
            $table->foreignId('lead_id')->nullable()->unique()->constrained();
            $table->jsonb('closeout_checklist')->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('project_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained(); // copied from the staff profile for record scoping
            $table->string('role', 12);
            $table->foreignId('engagement_id')->nullable()->constrained(); // rate agreement version for this assignment
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->timestampsTz();
            $table->unique(['project_id', 'staff_profile_id', 'from_date']);
        });
        DB::statement("ALTER TABLE project_members ADD CONSTRAINT project_members_role_check CHECK (role IN ('pm','lead','member','trainer'))");

        Schema::create('milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('deliverable')->nullable();
            $table->date('due_on')->nullable();
            $table->string('status', 12)->default('pending');
            $table->text('blocker')->nullable();
            $table->decimal('billable_amount', 18, 4)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE milestones ADD CONSTRAINT milestones_status_check CHECK (status IN ('pending','in_progress','blocked','done'))");
        DB::statement("ALTER TABLE milestones ADD CONSTRAINT milestones_blocker_check CHECK (status <> 'blocked' OR blocker IS NOT NULL)");

        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('assignee_staff_id')->nullable()->constrained('staff_profiles');
            $table->date('due_on')->nullable();
            $table->string('status', 12)->default('todo');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('todo','doing','done'))");
        DB::statement('ALTER TABLE timesheet_entries ADD CONSTRAINT timesheet_entries_task_fk FOREIGN KEY (task_id) REFERENCES tasks(id)');

        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('course_title');
            $table->date('session_date');
            $table->foreignId('trainer_staff_id')->nullable()->constrained('staff_profiles');
            $table->string('venue')->nullable();
            $table->string('status', 12)->default('planned');
            $table->decimal('feedback_score', 4, 2)->nullable();
            $table->text('feedback_comments')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE training_sessions ADD CONSTRAINT training_sessions_status_check CHECK (status IN ('planned','completed','cancelled'))");
        DB::statement('ALTER TABLE training_sessions ADD CONSTRAINT training_sessions_score_check CHECK (feedback_score IS NULL OR (feedback_score >= 0 AND feedback_score <= 5))');

        Schema::create('training_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('organisation')->nullable();
            $table->text('email')->nullable(); // encrypted (PII)
            $table->boolean('consent_given')->default(false);
            $table->boolean('attended')->default(false);
            $table->decimal('feedback_score', 4, 2)->nullable();
            $table->unsignedBigInteger('certificate_document_id')->nullable()->unique(); // ⚑ commercial_documents
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE training_participants ADD CONSTRAINT training_participants_certificate_fk FOREIGN KEY (certificate_document_id) REFERENCES commercial_documents(id)');
        DB::statement('ALTER TABLE training_participants ADD CONSTRAINT training_participants_score_check CHECK (feedback_score IS NULL OR (feedback_score >= 0 AND feedback_score <= 5))');
    }

    public function down(): void
    {
        Schema::dropIfExists('training_participants');
        Schema::dropIfExists('training_sessions');
        DB::statement('ALTER TABLE timesheet_entries DROP CONSTRAINT IF EXISTS timesheet_entries_task_fk');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('project_members');
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn(['billing_terms', 'cost_budget', 'closeout_checklist', 'description']);
        });
        Schema::dropIfExists('leads');
    }
};
