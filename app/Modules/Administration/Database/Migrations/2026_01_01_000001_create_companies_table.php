<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Company profile + bank accounts. Single company today; multi-company anticipated (SRS §1).
 *
 * @see SRS G-03, M03-08, M12-05
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('ssm_no')->nullable();
            $table->jsonb('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedBigInteger('logo_document_id')->nullable(); // FK added by M06
            $table->string('signatory_name')->nullable();
            $table->string('signatory_title')->nullable();
            $table->unsignedBigInteger('signatory_signature_document_id')->nullable();
            $table->char('base_currency', 3)->default('MYR');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_base_currency_check CHECK (base_currency = 'MYR')");

        Schema::create('company_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('bank_name');
            $table->text('account_no'); // encrypted cast; masked in UI (M04-06 analogue for company)
            $table->string('account_name');
            $table->unsignedBigInteger('gl_account_id')->nullable(); // FK added by M01
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_bank_accounts');
        Schema::dropIfExists('companies');
    }
};
