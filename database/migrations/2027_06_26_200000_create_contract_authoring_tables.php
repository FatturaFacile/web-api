<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contract authoring: clause library with versions and fallbacks, templates assembling clauses, redlining
     * (document versions, comments, negotiation rounds) and e-signature requests with signers and an audit trail.
     * New tables only; contracts are referenced by (contract_kind, contract_id) like the rest of the register.
     */
    public function up(): void
    {
        $this->create('contract_clauses', function (Blueprint $table): void {
            $table->string('code', 60);
            $table->string('title', 255);
            $table->string('category', 60)->nullable();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('current_version_no')->default(0);
            $table->index(['tenant_id', 'code']);
        });

        $this->create('contract_clause_versions', function (Blueprint $table): void {
            $table->foreignUuid('clause_id')->constrained('contract_clauses')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->longText('body');
            $table->string('change_note', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->unique(['clause_id', 'version_no']);
        });

        $this->create('contract_clause_fallbacks', function (Blueprint $table): void {
            $table->foreignUuid('clause_id')->constrained('contract_clauses')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->string('title', 255);
            $table->longText('body');
            $table->boolean('requires_approval')->default(false);
            $table->index(['clause_id', 'rank']);
        });

        $this->create('contract_templates', function (Blueprint $table): void {
            $table->string('name', 255);
            $table->string('contract_kind', 20)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('variables')->nullable();
            $table->index(['tenant_id', 'status']);
        });

        $this->create('contract_template_clauses', function (Blueprint $table): void {
            $table->foreignUuid('template_id')->constrained('contract_templates')->cascadeOnDelete();
            $table->foreignUuid('clause_id')->constrained('contract_clauses')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->boolean('is_optional')->default(false);
            $table->index(['template_id', 'position']);
        });

        $this->create('contract_negotiation_rounds', function (Blueprint $table): void {
            $table->string('contract_kind', 20);
            $table->uuid('contract_id');
            $table->unsignedInteger('round_no');
            $table->string('status', 20)->default('open');
            $table->string('outcome', 30)->nullable();
            $table->text('summary')->nullable();
            $table->uuid('opened_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->index(['contract_kind', 'contract_id']);
        });

        $this->create('contract_document_versions', function (Blueprint $table): void {
            $table->string('contract_kind', 20);
            $table->uuid('contract_id');
            $table->unsignedInteger('version_no');
            $table->longText('body');
            $table->string('source', 20)->default('edit');
            $table->uuid('template_id')->nullable();
            $table->json('variables')->nullable();
            $table->uuid('round_id')->nullable();
            $table->uuid('author_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('body_hash', 64);
            $table->unique(['contract_kind', 'contract_id', 'version_no']);
        });

        $this->create('contract_comments', function (Blueprint $table): void {
            $table->foreignUuid('document_version_id')->constrained('contract_document_versions')->cascadeOnDelete();
            $table->uuid('author_id')->nullable();
            $table->unsignedInteger('line_number')->nullable();
            $table->text('body');
            $table->string('status', 20)->default('open');
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
        });

        $this->create('contract_signature_requests', function (Blueprint $table): void {
            $table->string('contract_kind', 20);
            $table->uuid('contract_id');
            $table->foreignUuid('document_version_id')->constrained('contract_document_versions')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('provider_reference', 120)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('order_mode', 20)->default('sequential');
            $table->string('document_hash', 64);
            $table->date('expires_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->index(['contract_kind', 'contract_id']);
        });

        $this->create('contract_signers', function (Blueprint $table): void {
            $table->foreignUuid('request_id')->constrained('contract_signature_requests')->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->unsignedSmallInteger('position');
            $table->string('status', 20)->default('pending');
            $table->string('token_hash', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signature_hash', 64)->nullable();
            $table->string('decline_reason', 500)->nullable();
            $table->index(['request_id', 'position']);
        });

        $this->create('contract_signature_events', function (Blueprint $table): void {
            $table->foreignUuid('request_id')->constrained('contract_signature_requests')->cascadeOnDelete();
            $table->uuid('signer_id')->nullable();
            $table->string('event', 40);
            $table->uuid('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->index(['request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['contract_signature_events', 'contract_signers', 'contract_signature_requests', 'contract_comments', 'contract_document_versions', 'contract_negotiation_rounds', 'contract_template_clauses', 'contract_templates', 'contract_clause_fallbacks', 'contract_clause_versions', 'contract_clauses'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * @param  callable(Blueprint): void  $columns
     */
    private function create(string $name, callable $columns): void
    {
        if (Schema::hasTable($name)) {
            return;
        }

        Schema::create($name, function (Blueprint $table) use ($columns): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $columns($table);
            $table->timestamps();

            $table->softDeletes();
        });
    }
};
