<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 104 — Guided Data Migration & Import/Export Wizard (Issue #253).
 *
 * Dialect-agnostic schema (SQLite & PostgreSQL): nullable UUID tenant_id indexed as leading column on every table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->foreignUuid('created_by_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('source_system', 40)->index();
            $table->json('target_entities');
            $table->string('status', 30)->default('Uploaded')->index();
            $table->boolean('dry_run_passed')->default(false);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->string('source_file_path', 500)->nullable();
            $table->json('source_metadata_json')->nullable();
            $table->json('dry_run_summary_json')->nullable();
            $table->json('rollback_manifest_json')->nullable();
            $table->timestamp('dry_run_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'source_system'], 'migration_jobs_tenant_status_source_idx');
        });

        Schema::create('migration_field_mapping_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('source_system', 40)->index();
            $table->string('entity_type', 40)->index();
            $table->json('column_mappings_json');
            $table->json('value_transformations_json')->nullable();
            $table->boolean('is_system_preset')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'source_system', 'entity_type'], 'migration_templates_tenant_source_entity_idx');
        });

        Schema::create('migration_staging_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('migration_job_id')->index()->constrained('migration_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('entity_type', 40)->index();
            $table->string('source_record_identifier', 160)->nullable();
            $table->json('raw_payload_json');
            $table->json('mapped_payload_json')->nullable();
            $table->boolean('is_valid')->default(false);
            $table->boolean('is_imported')->default(false);
            $table->uuid('imported_record_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['migration_job_id', 'entity_type', 'row_index'], 'migration_staging_job_entity_row_idx');
        });

        Schema::create('migration_validation_errors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('migration_job_id')->index()->constrained('migration_jobs')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('source_record_identifier', 160)->nullable();
            $table->string('entity_type', 40)->index();
            $table->string('field_name', 100);
            $table->text('raw_value')->nullable();
            $table->string('error_code', 80)->index();
            $table->string('message_key', 160);
            $table->text('suggested_fix')->nullable();
            $table->boolean('is_resolved')->default(false)->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['migration_job_id', 'is_resolved', 'entity_type'], 'migration_errors_job_resolved_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_validation_errors');
        Schema::dropIfExists('migration_staging_rows');
        Schema::dropIfExists('migration_field_mapping_templates');
        Schema::dropIfExists('migration_jobs');
    }
};
