<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M03 expansion (P2): incoming client PO vs outgoing vendor PO (M03-05) and generated documents — staff memo,
 * appointment letter, training certificate (with a public verification token) and letter (M03-06). New columns
 * are covered by the existing issued-row immutability trigger automatically (it compares whole rows).
 *
 * @see SRS M03-05, M03-06, M03-07
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->string('external_ref', 80)->nullable(); // the client's own PO / LOA number
            $table->string('subject')->nullable();
            $table->text('body')->nullable(); // memo / letter text as issued
            $table->string('verification_token', 64)->nullable()->unique(); // certificates only
            $table->date('effective_date')->nullable(); // appointment start / PO date / certificate course date
        });
        DB::statement('ALTER TABLE commercial_documents DROP CONSTRAINT commercial_documents_type_check');
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_type_check CHECK (type IN ('quotation','invoice','credit_note','receipt','payment_voucher','client_po','vendor_po','memo','letter','appointment_letter','certificate'))");
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_certificate_token CHECK (type <> 'certificate' OR verification_token IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commercial_documents DROP CONSTRAINT IF EXISTS commercial_documents_certificate_token');
        DB::statement('ALTER TABLE commercial_documents DROP CONSTRAINT commercial_documents_type_check');
        DB::statement("ALTER TABLE commercial_documents ADD CONSTRAINT commercial_documents_type_check CHECK (type IN ('quotation','invoice','credit_note','receipt','payment_voucher','client_po','vendor_po','memo','letter','certificate'))");
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->dropColumn(['external_ref', 'subject', 'body', 'verification_token', 'effective_date']);
        });
    }
};
