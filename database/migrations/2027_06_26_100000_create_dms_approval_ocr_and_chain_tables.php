<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document approval workflows, the OCR pipeline, the hash chain over closed preservation packages and the expiry of a
     * check-out lock. New tables only (plus one nullable column); business rules live in the application.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('dms_documents', 'lock_expires_at')) {
            Schema::table('dms_documents', function (Blueprint $table): void {
                $table->timestamp('lock_expires_at')->nullable();
            });
        }

        if (! Schema::hasTable('dms_approval_templates')) {
            Schema::create('dms_approval_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 160);
                $table->string('document_type', 60)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('dms_approval_template_steps')) {
            Schema::create('dms_approval_template_steps', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('template_id')->constrained('dms_approval_templates')->cascadeOnDelete();
                $table->unsignedSmallInteger('position');
                $table->string('name', 160);
                $table->string('approver_type', 10);
                $table->uuid('approver_user_id')->nullable();
                $table->string('approver_role', 60)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['template_id', 'position']);
            });
        }

        if (! Schema::hasTable('dms_approval_requests')) {
            Schema::create('dms_approval_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('document_id')->constrained('dms_documents')->cascadeOnDelete();
                $table->uuid('document_version_id')->nullable();
                $table->uuid('template_id')->nullable();
                $table->string('template_name', 160);
                $table->string('status', 12)->default('pending');
                $table->unsignedSmallInteger('current_step')->default(1);
                $table->unsignedSmallInteger('total_steps');
                $table->uuid('requested_by')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['document_id', 'status']);
            });
        }

        if (! Schema::hasTable('dms_approval_decisions')) {
            Schema::create('dms_approval_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('request_id')->constrained('dms_approval_requests')->cascadeOnDelete();
                $table->unsignedSmallInteger('step_position');
                $table->string('step_name', 160);
                $table->uuid('decided_by')->nullable();
                $table->string('decision', 10);
                $table->string('comment', 1000)->nullable();
                $table->timestamp('decided_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['request_id', 'step_position']);
            });
        }

        if (! Schema::hasTable('dms_ocr_jobs')) {
            Schema::create('dms_ocr_jobs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('document_id')->constrained('dms_documents')->cascadeOnDelete();
                $table->uuid('document_version_id')->index();
                $table->string('status', 12)->default('queued');
                $table->string('engine', 60)->nullable();
                $table->longText('text_content')->nullable();
                $table->unsignedInteger('page_count')->nullable();
                $table->decimal('confidence', 5, 2)->nullable();
                $table->string('failure_reason', 500)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->uuid('requested_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('dms_preservation_chain_links')) {
            Schema::create('dms_preservation_chain_links', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->unsignedInteger('sequence');
                $table->uuid('package_id');
                $table->char('package_hash', 64);
                $table->char('previous_link_hash', 64);
                $table->char('link_hash', 64);
                $table->uuid('anchored_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'sequence']);
                $table->unique(['tenant_id', 'package_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (['dms_preservation_chain_links', 'dms_ocr_jobs', 'dms_approval_decisions', 'dms_approval_requests', 'dms_approval_template_steps', 'dms_approval_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('dms_documents', 'lock_expires_at')) {
            Schema::table('dms_documents', function (Blueprint $table): void {
                $table->dropColumn('lock_expires_at');
            });
        }
    }
};
