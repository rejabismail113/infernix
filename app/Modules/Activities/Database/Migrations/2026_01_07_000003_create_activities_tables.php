<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M07 Corporate activities & travel. Costs are NOT stored here: claim lines, expense lines and bill lines carry
 * activity_id (no copies, M07-02); budget vs actual is a read over those finance records.
 *
 * @see SRS M07-01, M07-02, M07-03
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('activity_no', 32)->unique();
            $table->string('type', 24);
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('location')->nullable();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('purpose')->nullable();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->decimal('budget', 18, 4)->default(0);
            $table->string('status', 16)->default('planned');
            $table->text('report')->nullable();
            $table->timestampTz('report_submitted_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['type', 'start_date']);
        });
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type_check CHECK (type IN ('event','site_visit','conference','trip','training_delivery','csr'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_status_check CHECK (status IN ('planned','ongoing','completed','cancelled'))");
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_dates_check CHECK (end_date >= start_date)');
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_budget_check CHECK (budget >= 0)');

        Schema::create('activity_attendees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('staff_profile_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('role', 40)->nullable();
            $table->timestampsTz();
            $table->unique(['activity_id', 'staff_profile_id']);
        });
        DB::statement('ALTER TABLE activity_attendees ADD CONSTRAINT activity_attendees_who_check CHECK (staff_profile_id IS NOT NULL OR name IS NOT NULL)');

        Schema::create('activity_itinerary_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->date('item_date');
            $table->string('kind', 16);
            $table->text('description');
            $table->decimal('estimated_cost', 18, 4)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE activity_itinerary_items ADD CONSTRAINT activity_itinerary_kind_check CHECK (kind IN ('travel','mileage','hotel','meal','transport','session','other'))");
        DB::statement('ALTER TABLE activity_itinerary_items ADD CONSTRAINT activity_itinerary_cost_check CHECK (estimated_cost >= 0)');
    }

    public function down(): void
    {
        foreach (['activity_itinerary_items', 'activity_attendees', 'activities'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
