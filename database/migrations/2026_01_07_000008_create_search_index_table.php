<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kernel global search index. Rows are written ONLY through App\Kernel\Search\SearchIndexer, which passes every
 * value through the SecretRedactor (M13-05, UAT-08); results are re-checked against the record's policy on read.
 *
 * @see SRS G-04, M13-05, UAT-08, NFR-02
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_index', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('module', 40);
            $table->timestampTz('indexed_at')->useCurrent();
            $table->unique(['entity_type', 'entity_id']);
        });
        DB::statement("CREATE INDEX search_index_text_idx ON search_index USING gin (to_tsvector('simple', title || ' ' || coalesce(body, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_index');
    }
};
