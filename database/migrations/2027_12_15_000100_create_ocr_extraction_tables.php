<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Intelligent document and invoice OCR extraction (Mod 092): the AI Inbox jobs with their normalized payload and
 * bounding boxes, the per-vendor coding predictions learned from accountant corrections, and the OCR provider choice
 * of each tenant. JSON columns are jsonb on PostgreSQL and text on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_extraction_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('dms_document_id')->constrained('dms_documents')->cascadeOnDelete();
            $table->string('source_channel', 24);
            $table->string('document_category', 24);
            $table->string('extraction_method', 24)->nullable();
            $table->string('ocr_provider_used', 32)->nullable();
            $table->decimal('overall_confidence_score', 5, 2)->default(0);
            $table->unsignedInteger('llm_tokens_used')->default(0);
            $table->jsonb('extracted_payload_json')->nullable();
            $table->jsonb('bounding_boxes_json')->nullable();
            $table->jsonb('review_draft_json')->nullable();
            $table->foreignUuid('vendor_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->jsonb('matched_purchase_order_ids_json')->nullable();
            $table->jsonb('matched_goods_receipt_ids_json')->nullable();
            $table->string('status', 24);
            $table->text('failure_reason')->nullable();
            $table->uuid('requested_by_user_id')->nullable();
            $table->uuid('confirmed_by_user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('resulting_entity_type', 64)->nullable();
            $table->uuid('resulting_entity_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status'], 'dej_tenant_status_index');
            $table->index(['tenant_id', 'dms_document_id'], 'dej_tenant_document_index');
        });

        Schema::create('vendor_coding_prediction_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vendor_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('line_description_pattern', 512);
            $table->foreignUuid('suggested_gl_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->foreignUuid('suggested_vat_code_id')->constrained('vat_codes')->cascadeOnDelete();
            $table->foreignUuid('suggested_cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->unsignedInteger('historical_hit_count')->default(1);
            $table->unsignedInteger('correction_count')->default(0);
            $table->decimal('accuracy_rate', 5, 2)->default(100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'vendor_id', 'line_description_pattern'], 'vcpm_tenant_vendor_pattern_unique');
        });

        Schema::create('tenant_ocr_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('primary_provider', 32)->default('ollama_local');
            $table->string('fallback_provider', 32)->nullable();
            $table->text('provider_settings_encrypted')->nullable();
            $table->decimal('auto_confirm_confidence_threshold', 5, 2)->default(98);
            $table->boolean('auto_confirm_enabled')->default(false);
            $table->string('dedicated_inbox_alias')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_ocr_configurations');
        Schema::dropIfExists('vendor_coding_prediction_models');
        Schema::dropIfExists('document_extraction_jobs');
    }
};
