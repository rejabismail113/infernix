<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client master v2 (owner request 2026-11-07): Client ID (CLT-2026-001), client type, prospect status, industry,
 * SST registration no., e-Invoice buyer identification (MyInvois) and multiple contact persons. Existing clients
 * get Client IDs in creation order per year, and the CLIENT numbering sequence continues after them.
 *
 * @see SRS M02-01, M03-01, §6.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('client_code', 20)->nullable();
            $table->string('client_type', 16)->default('private');
            $table->string('client_type_other', 120)->nullable();
            $table->string('industry', 120)->nullable();
            $table->string('sst_no', 40)->nullable();
            $table->string('einvoice_id_type', 12)->nullable();
            $table->string('einvoice_id_no', 60)->nullable();
            $table->unique(['company_id', 'client_code']);
        });
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_type_check CHECK (client_type IN ('government','glc','private','individual','ngo','other'))");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_einvoice_id_type_check CHECK (einvoice_id_type IS NULL OR einvoice_id_type IN ('brn','nric','passport','army'))");
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_status_check');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('prospect','active','inactive'))");

        Schema::create('client_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('name', 190);
            $table->string('position', 120)->nullable();
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index('client_id');
        });
        // At most one primary contact per client.
        DB::statement('CREATE UNIQUE INDEX client_contacts_one_primary ON client_contacts (client_id) WHERE is_primary');

        // Existing contact person → first (primary) contact.
        DB::statement("INSERT INTO client_contacts (client_id, name, email, phone, is_primary, sort_order, created_at, updated_at)
            SELECT id, contact_person, NULL, NULL, true, 0, now(), now() FROM clients WHERE coalesce(trim(contact_person), '') <> ''");

        // Client IDs for existing clients, per company and year of creation (Malaysia time).
        $next = [];
        foreach (DB::table('clients')->orderBy('id')->get(['id', 'company_id', 'created_at']) as $c) {
            $year = (int) Carbon::parse((string) $c->created_at)->timezone('Asia/Kuala_Lumpur')->format('Y');
            $n = ($next[$c->company_id][$year] ?? 0) + 1;
            $next[$c->company_id][$year] = $n;
            DB::table('clients')->where('id', $c->id)->update(['client_code' => sprintf('CLT-%d-%03d', $year, $n)]);
        }
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (array_unique(array_merge([2026, 2027], array_keys($next[$companyId] ?? []))) as $year) {
                $used = $next[$companyId][$year] ?? 0;
                $existing = DB::table('numbering_sequences')->where(['company_id' => $companyId, 'document_type' => 'CLIENT', 'fiscal_year' => $year])->first();
                if ($existing === null) {
                    DB::table('numbering_sequences')->insert(['company_id' => $companyId, 'document_type' => 'CLIENT', 'fiscal_year' => $year, 'prefix' => 'CLT',
                        'format' => '{prefix}-{year}-{seq}', 'next_number' => $used + 1, 'padding' => 3, 'created_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table('numbering_sequences')->where('id', $existing->id)->update(['next_number' => max((int) $existing->next_number, $used + 1)]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contacts');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_status_check');
        DB::statement("UPDATE clients SET status = 'active' WHERE status = 'prospect'");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_status_check CHECK (status IN ('active','inactive'))");
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_type_check');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_einvoice_id_type_check');
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'client_code']);
            $table->dropColumn(['client_code', 'client_type', 'client_type_other', 'industry', 'sst_no', 'einvoice_id_type', 'einvoice_id_no']);
        });
        DB::table('numbering_sequences')->where('document_type', 'CLIENT')->delete();
    }
};
