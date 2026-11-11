<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quality management system (QMS) of the company, independent from Manufacturing: non-conformities from any source,
     * corrective and preventive actions (CAPA), internal audits with checklists and findings, and controlled procedures.
     * Documents are referenced by id only (dms_documents.id, checked by the application), users by id. New tables only.
     */
    public function up(): void
    {
        Schema::create('qms_nonconformities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->string('source', 30);
            $table->string('severity', 20)->default('minor');
            $table->string('status', 30)->default('open');
            $table->date('detected_at');
            $table->uuid('detected_by')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('mfg_non_conformance_id')->nullable();
            $table->uuid('audit_finding_id')->nullable();
            $table->text('containment_action')->nullable();
            $table->text('root_cause')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('closure_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'source']);
            $table->index(['tenant_id', 'detected_at']);
        });

        DB::statement("ALTER TABLE qms_nonconformities ADD CONSTRAINT qms_nonconformities_source_check CHECK (source IN ('supplier', 'customer_complaint', 'internal', 'audit', 'manufacturing'))");
        DB::statement("ALTER TABLE qms_nonconformities ADD CONSTRAINT qms_nonconformities_severity_check CHECK (severity IN ('minor', 'major', 'critical'))");
        DB::statement("ALTER TABLE qms_nonconformities ADD CONSTRAINT qms_nonconformities_status_check CHECK (status IN ('open', 'investigating', 'capa_in_progress', 'closed', 'cancelled'))");

        Schema::create('qms_capa_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('nonconformity_id')->nullable()->constrained('qms_nonconformities')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('type', 20)->default('corrective');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->uuid('owner_id');
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->date('effectiveness_check_due_date')->nullable();
            $table->timestamp('effectiveness_checked_at')->nullable();
            $table->uuid('effectiveness_checked_by')->nullable();
            $table->string('effectiveness_result', 20)->nullable();
            $table->text('effectiveness_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'nonconformity_id']);
        });

        DB::statement("ALTER TABLE qms_capa_actions ADD CONSTRAINT qms_capa_actions_type_check CHECK (type IN ('corrective', 'preventive'))");
        DB::statement("ALTER TABLE qms_capa_actions ADD CONSTRAINT qms_capa_actions_status_check CHECK (status IN ('open', 'in_progress', 'completed', 'verified', 'ineffective', 'cancelled'))");
        DB::statement("ALTER TABLE qms_capa_actions ADD CONSTRAINT qms_capa_actions_result_check CHECK (effectiveness_result IS NULL OR effectiveness_result IN ('effective', 'not_effective'))");

        Schema::create('qms_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('title', 190);
            $table->text('scope')->nullable();
            $table->string('audit_type', 20)->default('internal');
            $table->date('planned_date');
            $table->date('performed_date')->nullable();
            $table->uuid('lead_auditor_id')->nullable();
            $table->string('auditee', 190)->nullable();
            $table->string('status', 20)->default('planned');
            $table->text('summary')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'planned_date']);
        });

        DB::statement("ALTER TABLE qms_audits ADD CONSTRAINT qms_audits_type_check CHECK (audit_type IN ('internal', 'process', 'supplier'))");
        DB::statement("ALTER TABLE qms_audits ADD CONSTRAINT qms_audits_status_check CHECK (status IN ('planned', 'in_progress', 'completed', 'cancelled'))");

        Schema::create('qms_audit_checklist_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('audit_id')->constrained('qms_audits')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('clause', 60)->nullable();
            $table->text('requirement');
            $table->string('result', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'audit_id']);
        });

        DB::statement("ALTER TABLE qms_audit_checklist_items ADD CONSTRAINT qms_audit_checklist_items_result_check CHECK (result IN ('pending', 'conforming', 'nonconforming', 'observation', 'not_applicable'))");

        Schema::create('qms_audit_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('audit_id')->constrained('qms_audits')->cascadeOnDelete();
            $table->foreignUuid('checklist_item_id')->nullable()->constrained('qms_audit_checklist_items')->nullOnDelete();
            $table->string('number', 30);
            $table->string('classification', 20);
            $table->text('description');
            $table->foreignUuid('nonconformity_id')->nullable()->constrained('qms_nonconformities')->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'audit_id']);
        });

        DB::statement("ALTER TABLE qms_audit_findings ADD CONSTRAINT qms_audit_findings_classification_check CHECK (classification IN ('major_nc', 'minor_nc', 'observation', 'opportunity'))");
        DB::statement("ALTER TABLE qms_audit_findings ADD CONSTRAINT qms_audit_findings_status_check CHECK (status IN ('open', 'closed'))");

        Schema::create('qms_procedures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->unsignedInteger('version')->default(1);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->uuid('document_id')->nullable();
            $table->uuid('owner_id')->nullable();
            $table->uuid('author_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('effective_date')->nullable();
            $table->date('review_due_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('workflow_instance_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code', 'version']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'document_id']);
        });

        DB::statement("ALTER TABLE qms_procedures ADD CONSTRAINT qms_procedures_status_check CHECK (status IN ('draft', 'pending_approval', 'approved', 'rejected', 'obsolete'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('qms_procedures');
        Schema::dropIfExists('qms_audit_findings');
        Schema::dropIfExists('qms_audit_checklist_items');
        Schema::dropIfExists('qms_audits');
        Schema::dropIfExists('qms_capa_actions');
        Schema::dropIfExists('qms_nonconformities');
    }
};
