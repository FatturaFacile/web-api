<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP Copilot: conversations, messages (with the staged human-in-the-loop action), the regulatory / tenant knowledge
 * chunks of the RAG pipeline and the token usage that feeds the per-tenant quota. The embedding is stored as a JSON list
 * of floats in a text column so the same schema works on SQLite and PostgreSQL; the similarity is computed in PHP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copilot_conversations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('title', 160);
            $table->string('locale', 5)->default('en');
            $table->string('context_route', 255)->nullable();
            $table->string('context_entity_type', 80)->nullable();
            $table->string('context_entity_id', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'user_id', 'updated_at']);
        });

        Schema::create('copilot_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('copilot_conversations')->cascadeOnDelete();
            $table->string('role', 12);
            $table->text('content_markdown')->default('');
            $table->json('citations_json')->nullable();
            $table->json('visualization_json')->nullable();
            $table->json('proposed_action_json')->nullable();
            $table->string('action_status', 12)->nullable();
            $table->json('token_usage_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->index(['tenant_id', 'action_status']);
        });

        Schema::create('copilot_knowledge_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->string('jurisdiction', 6);
            $table->string('locale', 5);
            $table->string('document_title', 200);
            $table->string('section_ref', 120)->nullable();
            $table->text('content_text');
            $table->text('embedding_vector')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'jurisdiction', 'locale']);
            $table->index(['tenant_id', 'source_type']);
        });

        Schema::create('copilot_usages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->string('provider', 30);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('copilot_usages');
        Schema::dropIfExists('copilot_knowledge_chunks');
        Schema::dropIfExists('copilot_messages');
        Schema::dropIfExists('copilot_conversations');
    }
};
