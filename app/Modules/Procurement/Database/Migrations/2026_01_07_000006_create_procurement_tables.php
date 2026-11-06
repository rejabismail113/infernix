<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M10 Procurement: vendor master extension, purchase requests (approval matrix), goods/service receipts against
 * vendor POs (M03 vendor_po), and SaaS subscriptions with renewal reviews. A subscription renewal alert creates a
 * REVIEW TASK only — money moves only when a bill/payment is recorded in M01 and linked (WF-06, UAT-12).
 *
 * @see SRS M10-01, M10-02, M10-04, M10-05, WF-02, WF-06, UAT-12
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('category', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
        });

        Schema::create('purchase_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('pr_no', 32)->unique();
            $table->string('title');
            $table->text('justification');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('activity_id')->nullable()->index();
            $table->date('needed_by')->nullable();
            $table->decimal('estimated_total', 18, 4)->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('current_level')->default(0);
            $table->text('rejection_reason')->nullable();
            $table->unsignedBigInteger('vendor_po_id')->nullable()->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE purchase_requests ADD CONSTRAINT purchase_requests_status_check CHECK (status IN ('draft','pending_approval','approved','rejected','ordered','cancelled'))");

        Schema::create('purchase_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('estimated_unit_price', 18, 4)->default(0);
            $table->decimal('estimated_amount', 18, 4)->default(0);
            $table->string('capitalization_class', 24)->default('EXPENSE');
            $table->unique(['purchase_request_id', 'line_no']);
        });
        DB::statement('ALTER TABLE purchase_request_lines ADD CONSTRAINT purchase_request_lines_qty_check CHECK (quantity > 0 AND estimated_unit_price >= 0)');
        DB::statement("ALTER TABLE purchase_request_lines ADD CONSTRAINT purchase_request_lines_class_check CHECK (capitalization_class IN ('CAPITAL_ASSET','LOW_VALUE_EQUIPMENT','CONSUMABLE','EXPENSE'))");

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('grn_no', 32)->unique();
            $table->unsignedBigInteger('vendor_po_id')->index();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->date('received_on');
            $table->string('kind', 8)->default('goods');
            $table->text('notes')->nullable();
            $table->string('status', 12)->default('received');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_kind_check CHECK (kind IN ('goods','service'))");
        DB::statement("ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_status_check CHECK (status IN ('received','cancelled'))");

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('po_line_id')->nullable();
            $table->text('description');
            $table->decimal('quantity_ordered', 12, 4)->default(0);
            $table->decimal('quantity_received', 12, 4);
            $table->string('condition', 40)->nullable();
        });
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_qty_check CHECK (quantity_received >= 0)');

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('product');
            $table->string('plan')->nullable();
            $table->unsignedBigInteger('license_owner_staff_id')->nullable()->index();
            $table->unsignedInteger('seats')->default(1);
            $table->decimal('price', 18, 4);
            $table->char('currency', 3)->default('MYR');
            $table->string('billing_cycle', 12);
            $table->boolean('auto_renew')->default(true);
            $table->date('start_date')->nullable();
            $table->date('renewal_date');
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['status', 'renewal_date']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_check CHECK (billing_cycle IN ('monthly','quarterly','annual','one_off'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('active','under_review','cancelled','expired'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_price_check CHECK (price >= 0 AND seats > 0)');

        Schema::create('subscription_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->date('renewal_date');
            $table->unsignedSmallInteger('alert_level');
            $table->string('status', 12)->default('open');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['subscription_id', 'renewal_date']);
        });
        DB::statement("ALTER TABLE subscription_reviews ADD CONSTRAINT subscription_reviews_status_check CHECK (status IN ('open','renew','cancel'))");
    }

    public function down(): void
    {
        foreach (['subscription_reviews', 'subscriptions', 'goods_receipt_lines', 'goods_receipts', 'purchase_request_lines', 'purchase_requests'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['category', 'email', 'phone', 'address', 'payment_terms_days', 'notes']));
    }
};
