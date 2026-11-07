<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document management. Files stay on the private disk: an imported attachment is only referenced by its path, so
     * no file is copied or moved and populated tenants need no data migration.
     */
    public function up(): void
    {
        Schema::create('dms_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('parent_id')->nullable()->index();
            $table->string('name', 190);
            $table->jsonb('access_roles')->default('[]');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('dms_folders', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('dms_folders')->restrictOnDelete();
        });

        Schema::create('dms_retention_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 190);
            $table->string('document_type', 100)->nullable();
            $table->unsignedInteger('retention_months');
            $table->string('action', 20)->default('archive');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dms_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('folder_id')->nullable()->index();
            $table->uuid('retention_policy_id')->nullable()->index();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('document_type', 100)->nullable()->index();
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('current_version')->default(0);
            $table->jsonb('access_roles')->default('[]');
            $table->jsonb('metadata')->default('{}');
            $table->text('search_text')->default('');
            $table->date('retention_until')->nullable()->index();
            $table->boolean('legal_hold')->default(false)->index();
            $table->string('legal_hold_reason', 500)->nullable();
            $table->timestamp('legal_hold_at')->nullable();
            $table->uuid('legal_hold_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('folder_id')->references('id')->on('dms_folders')->nullOnDelete();
            $table->foreign('retention_policy_id')->references('id')->on('dms_retention_policies')->nullOnDelete();
        });

        Schema::create('dms_document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('document_id')->index();
            $table->unsignedInteger('version_number');
            $table->string('disk', 60);
            $table->string('path', 1024);
            $table->string('original_name', 255);
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64);
            $table->uuid('attachment_id')->nullable()->index();
            $table->string('comment', 500)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('document_id')->references('id')->on('dms_documents')->cascadeOnDelete();
            $table->unique(['document_id', 'version_number'], 'uq_dms_version_number');
            $table->index(['tenant_id', 'sha256'], 'idx_dms_versions_hash');
        });

        Schema::create('dms_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 80);
            $table->string('color', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dms_document_tag', function (Blueprint $table): void {
            $table->uuid('document_id');
            $table->uuid('tag_id');
            $table->uuid('tenant_id')->index();

            $table->primary(['document_id', 'tag_id']);
            $table->foreign('document_id')->references('id')->on('dms_documents')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('dms_tags')->cascadeOnDelete();
        });

        Schema::create('dms_document_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('document_id')->index();
            $table->string('linkable_type', 60);
            $table->uuid('linkable_id');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('document_id')->references('id')->on('dms_documents')->cascadeOnDelete();
            $table->unique(['document_id', 'linkable_type', 'linkable_id'], 'uq_dms_document_link');
            $table->index(['tenant_id', 'linkable_type', 'linkable_id'], 'idx_dms_links_target');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX uq_dms_folder_name ON dms_folders (tenant_id, COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name)) WHERE deleted_at IS NULL");
            DB::statement('CREATE UNIQUE INDEX uq_dms_tag_name ON dms_tags (tenant_id, lower(name)) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX uq_dms_attachment_reference ON dms_document_versions (tenant_id, attachment_id) WHERE attachment_id IS NOT NULL AND deleted_at IS NULL');
            DB::statement("ALTER TABLE dms_documents ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, '') || ' ' || coalesce(document_type, '') || ' ' || coalesce(search_text, ''))) STORED");
            DB::statement('CREATE INDEX idx_dms_documents_search ON dms_documents USING GIN (search_vector)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dms_document_links');
        Schema::dropIfExists('dms_document_tag');
        Schema::dropIfExists('dms_tags');
        Schema::dropIfExists('dms_document_versions');
        Schema::dropIfExists('dms_documents');
        Schema::dropIfExists('dms_retention_policies');
        Schema::dropIfExists('dms_folders');
    }
};
