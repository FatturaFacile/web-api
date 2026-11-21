<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Services & Cognitive Automation Layer (Mod 093): the provider choice of each tenant (credentials encrypted), prompt
 * templates, the automation rules that decide between zero-click approval and the human review queue, the suggestions of
 * that queue, the embeddings of the tenant knowledge index, the usage and cost log and the append-only audit trail.
 * Embeddings are stored as JSON text so the same table works on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 24);
            $table->string('label', 120)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('endpoint', 255)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('light_model', 100)->nullable();
            $table->string('embedding_model', 100)->nullable();
            $table->text('credentials_encrypted')->nullable();
            $table->string('max_data_classification', 24)->default('internal');
            $table->boolean('pii_masking_enabled')->default(true);
            $table->unsignedInteger('latency_sla_ms')->nullable();
            $table->unsignedBigInteger('monthly_token_budget')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'provider'], 'aipc_tenant_provider_unique');
        });

        Schema::create('ai_prompt_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('locale', 8)->default('en');
            $table->unsignedInteger('version')->default(1);
            $table->text('system_prompt');
            $table->text('user_prompt')->nullable();
            $table->jsonb('output_schema_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'key', 'locale', 'is_active'], 'aipt_lookup_index');
        });

        Schema::create('ai_automation_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('workflow', 40);
            $table->boolean('is_enabled')->default(true);
            $table->boolean('auto_approve_enabled')->default(false);
            $table->decimal('auto_approve_min_confidence', 4, 2)->default(0.95);
            $table->decimal('max_auto_amount', 14, 2)->nullable();
            $table->string('data_classification', 24)->default('internal');
            $table->string('preferred_provider', 24)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'workflow'], 'aiar_tenant_workflow_unique');
        });

        Schema::create('ai_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('workflow', 40);
            $table->string('source_type', 24);
            $table->string('source_reference', 120)->nullable();
            $table->text('source_text')->nullable();
            $table->jsonb('proposed_payload_json');
            $table->jsonb('field_confidences_json')->nullable();
            $table->decimal('confidence', 4, 2)->default(0);
            $table->decimal('amount', 14, 2)->nullable();
            $table->text('reasoning')->nullable();
            $table->jsonb('citations_json')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('priority', 12)->default('normal');
            $table->string('fingerprint', 64)->nullable();
            $table->string('provider_used', 24)->nullable();
            $table->string('model_used', 100)->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_feedback')->nullable();
            $table->jsonb('final_payload_json')->nullable();
            $table->string('resulting_entity_type', 64)->nullable();
            $table->uuid('resulting_entity_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'status', 'priority'], 'aisg_queue_index');
            $table->index(['tenant_id', 'kind', 'status'], 'aisg_kind_index');
            $table->index(['tenant_id', 'fingerprint'], 'aisg_fingerprint_index');
        });

        Schema::create('ai_embeddings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->unsignedInteger('chunk_index')->default(0);
            $table->text('content');
            $table->string('content_hash', 64);
            $table->text('embedding_json');
            $table->unsignedInteger('dimension');
            $table->string('model', 100);
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'source_type', 'source_id', 'chunk_index'], 'aiem_source_unique');
        });

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->string('provider', 24);
            $table->string('model', 100)->nullable();
            $table->string('task_type', 40);
            $table->string('data_classification', 24);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->boolean('pii_masked')->default(false);
            $table->string('status', 24);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'created_at'], 'aiul_tenant_time_index');
            $table->index(['tenant_id', 'provider', 'created_at'], 'aiul_provider_time_index');
        });

        Schema::create('ai_audit_trails', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('event_type', 40);
            $table->uuid('actor_user_id')->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('provider', 24)->nullable();
            $table->string('model', 100)->nullable();
            $table->decimal('confidence', 4, 2)->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'sequence'], 'aiat_chain_unique');
            $table->index(['tenant_id', 'event_type', 'created_at'], 'aiat_event_index');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'aiat_subject_index');
        });
    }

    public function down(): void
    {
        foreach (['ai_audit_trails', 'ai_usage_logs', 'ai_embeddings', 'ai_suggestions', 'ai_automation_rules', 'ai_prompt_templates', 'ai_provider_configs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
