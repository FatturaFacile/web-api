<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Governance, risk and compliance (GRC): risk register, controls library with control tests, internal audit plan,
     * audits, findings with remediation actions, and the compliance obligations calendar. Users and DMS documents are
     * referenced by id only (checked by the application). The tenant_id is nullable like on every table of the product. Allowed values are enforced by the application, never by
     * database constraints. New tables only.
     */
    public function up(): void
    {
        Schema::create('gov_risks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('category', 30);
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('inherent_score');
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->unsignedTinyInteger('residual_score')->nullable();
            $table->string('treatment', 20)->nullable();
            $table->text('treatment_notes')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('status', 20)->default('identified');
            $table->date('next_review_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'residual_score']);
        });

        Schema::create('gov_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('control_type', 20);
            $table->string('frequency', 20);
            $table->string('framework_reference', 120)->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('last_tested_at')->nullable();
            $table->string('last_test_result', 20)->nullable();
            $table->date('next_test_due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'next_test_due_date']);
        });

        Schema::create('gov_risk_controls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('risk_id')->constrained('gov_risks')->cascadeOnDelete();
            $table->foreignUuid('control_id')->constrained('gov_controls')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'risk_id', 'control_id']);
            $table->index(['tenant_id', 'control_id']);
        });

        Schema::create('gov_control_test_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('control_id')->constrained('gov_controls')->cascadeOnDelete();
            $table->date('tested_on');
            $table->uuid('tester_id')->nullable();
            $table->string('result', 20);
            $table->unsignedInteger('sample_size')->nullable();
            $table->unsignedInteger('exceptions_found')->nullable();
            $table->text('notes')->nullable();
            $table->json('evidence_document_ids')->nullable();
            $table->uuid('finding_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'control_id', 'tested_on']);
            $table->index(['tenant_id', 'result']);
        });

        Schema::create('gov_audit_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'year']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('gov_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('audit_plan_id')->nullable()->constrained('gov_audit_plans')->nullOnDelete();
            $table->string('code', 30);
            $table->string('title', 190);
            $table->string('audit_type', 20);
            $table->text('scope');
            $table->text('objectives')->nullable();
            $table->uuid('lead_auditor_id');
            $table->json('team_member_ids')->nullable();
            $table->date('planned_start');
            $table->date('planned_end');
            $table->date('actual_start')->nullable();
            $table->date('actual_end')->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('conclusion')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'audit_plan_id']);
            $table->index(['tenant_id', 'planned_start']);
        });

        Schema::create('gov_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('audit_id')->nullable()->constrained('gov_audits')->nullOnDelete();
            $table->foreignUuid('control_id')->nullable()->constrained('gov_controls')->nullOnDelete();
            $table->foreignUuid('risk_id')->nullable()->constrained('gov_risks')->nullOnDelete();
            $table->string('code', 30);
            $table->string('source', 20);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('severity', 20);
            $table->text('recommendation')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('accepted_reason')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->uuid('accepted_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('closure_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'severity']);
            $table->index(['tenant_id', 'audit_id']);
        });

        Schema::create('gov_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('finding_id')->constrained('gov_findings')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->uuid('owner_id');
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verified_by')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'finding_id']);
            $table->index(['tenant_id', 'owner_id']);
        });

        Schema::create('gov_obligations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('template_key', 60)->nullable();
            $table->string('framework', 20);
            $table->string('jurisdiction', 10);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('legal_reference', 190)->nullable();
            $table->string('recurrence', 20)->default('annual');
            $table->date('due_date');
            $table->unsignedSmallInteger('reminder_days')->default(30);
            $table->uuid('owner_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('last_completed_at')->nullable();
            $table->uuid('last_completed_by')->nullable();
            $table->uuid('evidence_document_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'template_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'framework']);
        });
    }

    public function down(): void
    {
        foreach (['gov_obligations', 'gov_actions', 'gov_findings', 'gov_audits', 'gov_audit_plans', 'gov_control_test_runs', 'gov_risk_controls', 'gov_controls', 'gov_risks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
