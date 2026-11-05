<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free numbering: company + document_type + fiscal_year + sequence, allocated with SELECT … FOR UPDATE
 * inside the caller's transaction (CLAUDE.md rule 6).
 *
 * @see SRS §6.3, G-03, M03-04, M03-08, M12-05
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numbering_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 32);
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('prefix', 16);
            $table->string('format', 64)->default('{prefix}-{year}-{seq}');
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(4);
            $table->timestampsTz();
            $table->unique(['company_id', 'document_type', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_sequences');
    }
};
