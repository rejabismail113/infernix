<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document Centre: documents (metadata, classification, retention, legal hold, soft archive, hard-delete
 * marker), immutable versions, validated polymorphic links, explicit shares, retention and evidence rules.
 *
 * @see SRS M06-01 … M06-05, G-04, G-07, G-10, §6.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('retain_months');
            $table->string('basis', 16)->default('upload'); // upload | entity_closed
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE retention_rules ADD CONSTRAINT retention_rules_basis_check CHECK (basis IN ('upload','entity_closed'))");
        DB::statement('ALTER TABLE retention_rules ADD CONSTRAINT retention_rules_months_check CHECK (retain_months > 0)');

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('classification', 20)->default('confidential');
            $table->jsonb('tags')->nullable();
            $table->foreignId('retention_rule_id')->nullable()->constrained('retention_rules')->restrictOnDelete();
            $table->date('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->text('legal_hold_reason')->nullable();
            $table->unsignedSmallInteger('current_version_no')->default(0);
            $table->boolean('frozen')->default(false);
            $table->timestampTz('frozen_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('hard_deleted_at')->nullable();
            $table->foreignId('hard_deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('hard_delete_reason')->nullable();
            $table->timestampTz('uploaded_at');
            $table->timestampsTz();
            $table->index(['company_id', 'classification']);
            $table->index('owner_id');
            $table->index('archived_at');
            $table->index('hard_deleted_at');
            $table->index('retention_until');
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_classification_check CHECK (classification IN ('public_internal','confidential','restricted','medical'))");
        DB::statement('CREATE INDEX documents_tags_gin ON documents USING GIN (tags)');

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->string('file_name');
            $table->string('extension', 10);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('content_hash', 64);
            $table->string('storage_key')->unique();
            $table->string('scan_status', 10)->default('pending');
            $table->string('scanner', 40)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'version_no']);
            $table->index('content_hash');
        });
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_size_check CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE document_versions ADD CONSTRAINT document_versions_scan_check CHECK (scan_status IN ('pending','clean','infected','skipped','error'))");

        // Versions are immutable (only scan_status/scanner may change) and never deleted (metadata is kept
        // even after a hard delete removed the object). No new version may be added to a FROZEN document.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION document_versions_immutable() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'document_versions are immutable: rows are never deleted (M06-03)';
    END IF;
    IF NEW.document_id <> OLD.document_id OR NEW.version_no <> OLD.version_no OR NEW.file_name <> OLD.file_name
       OR NEW.extension <> OLD.extension OR NEW.mime_type <> OLD.mime_type OR NEW.size_bytes <> OLD.size_bytes
       OR NEW.content_hash <> OLD.content_hash OR NEW.storage_key <> OLD.storage_key
       OR NEW.uploaded_by <> OLD.uploaded_by OR NEW.created_at <> OLD.created_at THEN
        RAISE EXCEPTION 'document_versions are immutable: only scan_status may change (M06-03)';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER document_versions_immutable BEFORE UPDATE OR DELETE ON document_versions
    FOR EACH ROW EXECUTE FUNCTION document_versions_immutable();

CREATE OR REPLACE FUNCTION document_versions_frozen_guard() RETURNS trigger AS $$
DECLARE is_frozen boolean;
BEGIN
    SELECT frozen INTO is_frozen FROM documents WHERE id = NEW.document_id;
    IF is_frozen THEN
        RAISE EXCEPTION 'document % is frozen (linked to an issued document): no new versions (M06-03, M03-07)', NEW.document_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER document_versions_frozen_guard BEFORE INSERT ON document_versions
    FOR EACH ROW EXECUTE FUNCTION document_versions_frozen_guard();
SQL);

        Schema::create('entity_document_links', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 48);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('link_type', 32);
            $table->boolean('required_evidence')->default(false);
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['entity_type', 'entity_id', 'document_id', 'link_type']);
            $table->index(['entity_type', 'entity_id']);
            $table->index('document_id');
        });
        DB::statement("ALTER TABLE entity_document_links ADD CONSTRAINT entity_document_links_type_check CHECK (entity_type ~ '^[a-z_]+$' AND link_type ~ '^[a-z_]+$')");

        Schema::create('document_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 8)->default('view');
            $table->timestampTz('expires_at')->nullable();
            $table->foreignId('shared_by')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'user_id']);
        });
        DB::statement("ALTER TABLE document_shares ADD CONSTRAINT document_shares_permission_check CHECK (permission IN ('view'))");

        Schema::create('required_evidence_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 48);
            $table->string('condition', 120)->default('always');
            $table->string('link_type', 32);
            $table->string('description');
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->unique(['entity_type', 'condition', 'link_type']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->foreign('logo_document_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('signatory_signature_document_id')->references('id')->on('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['logo_document_id']);
            $table->dropForeign(['signatory_signature_document_id']);
        });
        Schema::dropIfExists('required_evidence_rules');
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('entity_document_links');
        Schema::dropIfExists('document_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS document_versions_immutable(); DROP FUNCTION IF EXISTS document_versions_frozen_guard();');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('retention_rules');
    }
};
