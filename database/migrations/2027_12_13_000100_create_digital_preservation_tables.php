<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digital preservation (Italian AgID conservazione, Swiss GeBüV/Olico): configuration, PdV/PdA batches, preserved items
 * and the hash-chained audit log. Items live in `preservation_batch_items` because `preservation_items` already belongs
 * to the document-trust packages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preservation_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('jurisdiction', 4)->default('DUAL');
            $table->string('provider', 30)->default('local_worm');
            $table->text('credentials')->nullable();
            $table->string('preservation_officer_name', 200)->nullable();
            $table->string('preservation_officer_tax_code', 32)->nullable();
            $table->string('tsa_url', 500)->nullable();
            $table->text('tsa_credentials')->nullable();
            $table->string('auto_seal_frequency', 10)->default('monthly');
            $table->unsignedSmallInteger('default_retention_years')->default(10);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });

        Schema::create('preservation_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('batch_number', 40);
            $table->string('jurisdiction', 4);
            $table->string('status', 12)->default('draft');
            $table->string('document_category', 30);
            $table->date('period_from');
            $table->date('period_to');
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedBigInteger('total_size_bytes')->default(0);
            $table->string('pdv_xml_path', 500)->nullable();
            $table->string('pda_archive_path', 500)->nullable();
            $table->string('pdv_sha256', 64)->nullable();
            $table->string('pda_sha256', 64)->nullable();
            $table->string('merkle_root_sha256', 64)->nullable();
            $table->string('previous_batch_sha256', 64)->nullable();
            $table->string('rfc3161_token_path', 500)->nullable();
            $table->timestamp('rfc3161_gen_time')->nullable();
            $table->string('rfc3161_provider', 100)->nullable();
            $table->boolean('rfc3161_qualified')->default(false);
            $table->string('external_conservator_id', 200)->nullable();
            $table->string('rdv_receipt_path', 500)->nullable();
            $table->date('retention_until')->nullable();
            $table->uuid('sealed_by')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'batch_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'document_category']);
        });

        Schema::create('preservation_batch_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('preservation_batch_id')->constrained('preservation_batches')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('preservable_type', 100);
            $table->string('preservable_id', 64);
            $table->uuid('document_uuid');
            $table->string('document_type', 60);
            $table->string('document_number', 100)->nullable();
            $table->date('document_date')->nullable();
            $table->string('counterparty_name', 200)->nullable();
            $table->string('counterparty_tax_id', 40)->nullable();
            $table->string('original_filename', 255);
            $table->string('archived_filename', 255);
            $table->string('archive_path', 500);
            $table->string('mime_type', 100);
            $table->boolean('is_pdfa_compliant')->default(false);
            $table->string('pdfa_conformance_level', 10)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->string('sha256_hash', 64);
            $table->json('metadata_json')->nullable();
            $table->date('retention_until')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['preservation_batch_id', 'sequence']);
            $table->index(['tenant_id', 'preservable_type', 'preservable_id'], 'pbi_preservable_idx');
            $table->index(['tenant_id', 'document_number']);
            $table->index(['tenant_id', 'sha256_hash']);
            $table->index(['tenant_id', 'counterparty_tax_id']);
        });

        Schema::create('preservation_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->uuid('preservation_batch_id')->nullable();
            $table->uuid('preservation_item_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 30);
            $table->json('details_json')->nullable();
            $table->string('sha256_snapshot', 64)->nullable();
            $table->string('previous_log_sha256', 64)->nullable();
            $table->string('entry_sha256', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at');

            $table->unique(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'action']);
            $table->index(['tenant_id', 'preservation_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preservation_audit_logs');
        Schema::dropIfExists('preservation_batch_items');
        Schema::dropIfExists('preservation_batches');
        Schema::dropIfExists('preservation_configurations');
    }
};
