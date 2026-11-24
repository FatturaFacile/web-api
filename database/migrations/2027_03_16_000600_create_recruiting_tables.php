<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recruiting. The personal data of a candidate are encrypted by the models, so they are text columns, and their models
     * stay out of the audit ledger: an application must be erasable. Deleting an application takes its stage history and
     * interview notes with it. retention_days is a company choice: the law sets no fixed term, it asks for no more than
     * necessary (art. 6 LPD, art. 5 GDPR).
     */
    public function up(): void
    {
        Schema::create('hr_recruiting_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('retention_days')->default(180);
            $table->timestamps();
        });

        Schema::create('hr_pipeline_stages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('outcome', 20)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_job_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 180);
            $table->text('description')->nullable();
            $table->foreignUuid('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->string('location', 120)->nullable();
            $table->string('employment_type', 40)->default('full_time');
            $table->string('status', 20)->default('draft');
            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('hr_job_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('job_posting_id')->constrained('hr_job_postings')->cascadeOnDelete();
            $table->foreignUuid('pipeline_stage_id')->constrained('hr_pipeline_stages')->restrictOnDelete();
            $table->text('first_name');
            $table->text('last_name');
            $table->text('email');
            $table->text('phone')->nullable();
            $table->text('cover_note')->nullable();
            $table->string('source', 60)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('consent_until')->nullable();
            $table->date('retention_until')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->uuid('employee_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'job_posting_id', 'status']);
            $table->index(['tenant_id', 'retention_until']);
        });

        Schema::create('hr_application_stage_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('job_application_id')->constrained('hr_job_applications')->cascadeOnDelete();
            $table->foreignUuid('pipeline_stage_id')->constrained('hr_pipeline_stages')->restrictOnDelete();
            $table->uuid('actor_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'job_application_id']);
        });

        Schema::create('hr_interview_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('job_application_id')->constrained('hr_job_applications')->cascadeOnDelete();
            $table->uuid('interviewer_id')->nullable();
            $table->timestamp('interviewed_at')->nullable();
            $table->unsignedSmallInteger('rating')->nullable();
            $table->text('note');
            $table->timestamps();

            $table->index(['tenant_id', 'job_application_id']);
        });

        Schema::create('hr_onboarding_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->jsonb('tasks');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('hr_onboarding_checklists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignUuid('onboarding_template_id')->nullable()->constrained('hr_onboarding_templates')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('hr_onboarding_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('onboarding_checklist_id')->constrained('hr_onboarding_checklists')->cascadeOnDelete();
            $table->string('title', 180);
            $table->date('due_on')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'onboarding_checklist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_onboarding_tasks');
        Schema::dropIfExists('hr_onboarding_checklists');
        Schema::dropIfExists('hr_onboarding_templates');
        Schema::dropIfExists('hr_interview_notes');
        Schema::dropIfExists('hr_application_stage_events');
        Schema::dropIfExists('hr_job_applications');
        Schema::dropIfExists('hr_job_postings');
        Schema::dropIfExists('hr_pipeline_stages');
        Schema::dropIfExists('hr_recruiting_settings');
    }
};
