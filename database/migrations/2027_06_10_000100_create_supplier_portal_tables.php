<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Supplier lifecycle and portal: onboarding workflow, required documents with expiry, qualification scoring,
     * supplier checks and risk profiles, and bank detail change requests with dual approval. Suppliers, RFQs, quotes and
     * qualification documents of the procurement module are referenced by id, never duplicated. New tables only; the
     * statuses are validated by the application so the schema runs on every database engine.
     */
    public function up(): void
    {
        Schema::create('sp_onboardings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->string('status', 30)->default('invited');
            $table->string('country', 2)->default('IT');
            $table->string('invitation_email', 255)->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->json('registration')->nullable();
            $table->timestamp('registration_submitted_at')->nullable();
            $table->timestamp('documents_submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('sp_document_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('country', 2)->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('requires_expiry')->default(true);
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->unsignedSmallInteger('alert_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('sp_document_submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->uuid('onboarding_id')->nullable();
            $table->foreignUuid('requirement_id')->constrained('sp_document_requirements')->cascadeOnDelete();
            $table->uuid('supplier_document_id')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->string('reference', 100)->nullable();
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('file_path', 500)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('submitted_via', 10)->default('internal');
            $table->uuid('submitted_by')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'supplier_id', 'status']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['tenant_id', 'requirement_id']);
        });

        Schema::create('sp_document_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('submission_id')->constrained('sp_document_submissions')->cascadeOnDelete();
            $table->unsignedSmallInteger('threshold_days');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'submission_id', 'threshold_days'], 'sp_document_alerts_unique');
        });

        Schema::create('sp_qualification_criteria', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('weight', 6, 2);
            $table->unsignedSmallInteger('max_score')->default(5);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('sp_qualification_assessments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->uuid('onboarding_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('total_score', 6, 2)->nullable();
            $table->string('band', 1)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->uuid('assessed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'supplier_id', 'status']);
        });

        Schema::create('sp_qualification_scores', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('assessment_id')->constrained('sp_qualification_assessments')->cascadeOnDelete();
            $table->foreignUuid('criterion_id')->constrained('sp_qualification_criteria')->cascadeOnDelete();
            $table->decimal('score', 6, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['assessment_id', 'criterion_id']);
            $table->index(['tenant_id', 'assessment_id']);
        });

        Schema::create('sp_supplier_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->string('check_type', 30);
            $table->string('result', 10)->default('pending');
            $table->date('checked_at')->nullable();
            $table->date('valid_until')->nullable();
            $table->uuid('checked_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'supplier_id', 'check_type']);
            $table->index(['tenant_id', 'result']);
        });

        Schema::create('sp_risk_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->string('level', 10)->default('none');
            $table->unsignedSmallInteger('score')->default(0);
            $table->json('reasons')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'supplier_id']);
            $table->index(['tenant_id', 'level']);
        });

        Schema::create('sp_bank_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('supplier_id');
            $table->string('source', 10)->default('internal');
            $table->uuid('requested_by')->nullable();
            $table->string('account_holder', 150);
            $table->string('iban', 34);
            $table->string('bic', 11)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('previous_iban', 34)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('callback_verified_at')->nullable();
            $table->uuid('callback_verified_by')->nullable();
            $table->text('callback_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('sp_bank_change_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('request_id')->constrained('sp_bank_change_requests')->cascadeOnDelete();
            $table->uuid('user_id');
            $table->string('decision', 10);
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['request_id', 'user_id']);
            $table->index(['tenant_id', 'request_id']);
        });
    }
};
