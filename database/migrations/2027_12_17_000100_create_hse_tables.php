<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workplace health, safety and mandatory training (Mod 099, D.Lgs. 81/2008 and CFST/SUVA): the requirement matrices,
 * the safety certificates, the occupational medical visits with their segregated clinical vault, the PPE delivery
 * records, the incident registry with the statutory claim drafts, the DVR / MSSL audits, the tool-box talks and the
 * idempotency log of the expiration alerts. Cross-module references (employee, product, warehouse, document) are plain
 * uuid columns. JSON columns are jsonb on PostgreSQL and text on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hse_requirement_matrices', function (Blueprint $table): void {
            $this->base($table);
            $table->string('name', 160);
            $table->string('jurisdiction', 2);
            $table->uuid('job_position_id')->nullable();
            $table->uuid('work_center_id')->nullable();
            $table->uuid('construction_site_id')->nullable();
            $table->string('suva_hazard_class', 16)->nullable();
            $table->string('dvr_risk_profile_code', 48)->nullable();
            $table->jsonb('required_training_types_json')->nullable();
            $table->jsonb('required_medical_exam_codes_json')->nullable();
            $table->jsonb('required_ppe_product_ids_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $this->audit($table);

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'job_position_id']);
            $table->index(['tenant_id', 'work_center_id']);
            $table->index(['tenant_id', 'construction_site_id']);
        });

        Schema::create('hse_safety_certificates', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('employee_id');
            $table->string('certificate_type', 48);
            $table->string('certificate_number', 96)->nullable();
            $table->string('training_provider_name', 160)->nullable();
            $table->decimal('hours_completed', 6, 2)->default(0);
            $table->date('issued_at');
            $table->date('expires_at')->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('status', 16)->default('valid');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $this->audit($table);

            $table->index(['tenant_id', 'employee_id', 'certificate_type']);
            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('hse_medical_visits', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('employee_id');
            $table->string('doctor_name', 160);
            $table->string('doctor_registration_number', 64)->nullable();
            $table->string('visit_type', 32);
            $table->date('visit_date');
            $table->date('next_visit_due_date')->nullable();
            $table->string('fitness_outcome', 48);
            $table->text('restrictions_summary')->nullable();
            $table->string('encrypted_clinical_notes_ref', 96)->nullable();
            $table->uuid('certificate_document_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $this->audit($table);

            $table->index(['tenant_id', 'employee_id', 'visit_date']);
            $table->index(['tenant_id', 'next_visit_due_date']);
        });

        // Clinical notes live apart from the visit row: nothing of the standard HR/RSPP queries joins this table.
        Schema::create('hse_medical_clinical_vault', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('medical_visit_id')->constrained('hse_medical_visits')->cascadeOnDelete();
            $table->text('encrypted_notes');
            $this->audit($table);

            $table->unique('medical_visit_id');
            $table->index(['tenant_id', 'medical_visit_id']);
        });

        Schema::create('hse_employee_clearance_cache', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('employee_id');
            $table->string('medical_status', 48);
            $table->text('restrictions_summary')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamp('computed_at')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'employee_id']);
        });

        Schema::create('hse_ppe_distributions', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('employee_id');
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->string('lot_or_serial_id', 96)->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('ce_marking_standard', 96)->nullable();
            $table->timestamp('handed_over_at');
            $table->date('replacement_due_at')->nullable();
            $table->string('employee_signature_hash', 64);
            $table->longText('signature_image_base64_or_ref')->nullable();
            $table->uuid('verbale_consegna_pdf_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->string('locale', 8)->default('en');
            $table->string('delivery_status', 24)->default('delivered_signed');
            $this->audit($table);

            $table->index(['tenant_id', 'employee_id', 'product_id']);
            $table->index(['tenant_id', 'replacement_due_at']);
        });

        Schema::create('hse_incident_reports', function (Blueprint $table): void {
            $this->base($table);
            $table->string('protocol_number', 32);
            $table->string('jurisdiction', 2);
            $table->timestamp('incident_date');
            $table->timestamp('reported_at');
            $table->string('location_or_site_id', 96)->nullable();
            $table->uuid('work_center_id')->nullable();
            $table->string('incident_type', 40);
            $table->jsonb('involved_employee_ids_json')->nullable();
            $table->jsonb('witness_employee_ids_json')->nullable();
            $table->text('description');
            $table->text('immediate_actions_taken')->nullable();
            $table->jsonb('root_cause_analysis_json')->nullable();
            $table->jsonb('corrective_actions_json')->nullable();
            $table->unsignedInteger('prognosis_days')->default(0);
            $table->jsonb('injury_dynamics_json')->nullable();
            $table->uuid('linked_insurance_claim_id')->nullable();
            $table->string('status', 24)->default('draft');
            $this->audit($table);

            $table->unique(['tenant_id', 'protocol_number']);
            $table->index(['tenant_id', 'incident_type', 'status']);
        });

        Schema::create('hse_accident_claim_drafts', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('incident_report_id')->constrained('hse_incident_reports')->cascadeOnDelete();
            $table->string('jurisdiction', 2);
            $table->string('schema_version', 32);
            $table->string('declaration_type', 32)->nullable();
            $table->jsonb('payload_json');
            $table->string('status', 16)->default('draft');
            $this->audit($table);

            $table->index(['tenant_id', 'incident_report_id']);
        });

        Schema::create('hse_safety_audits', function (Blueprint $table): void {
            $this->base($table);
            $table->string('audit_type', 24);
            $table->string('jurisdiction', 2);
            $table->date('audit_date');
            $table->jsonb('answers_json')->nullable();
            $table->decimal('score_percent', 5, 2)->default(0);
            $table->jsonb('corrective_actions_json')->nullable();
            $table->string('status', 16)->default('open');
            $this->audit($table);

            $table->index(['tenant_id', 'audit_type', 'audit_date']);
        });

        Schema::create('hse_dvr_documents', function (Blueprint $table): void {
            $this->base($table);
            $table->unsignedInteger('revision_number');
            $table->date('issued_at');
            $table->date('review_due_at')->nullable();
            $table->jsonb('signatures_json')->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('status', 16)->default('draft');
            $this->audit($table);

            $table->unique(['tenant_id', 'revision_number']);
        });

        Schema::create('hse_toolbox_talks', function (Blueprint $table): void {
            $this->base($table);
            $table->string('topic', 200);
            $table->string('site_or_work_center', 160)->nullable();
            $table->jsonb('checklist_points_json')->nullable();
            $table->uuid('supervisor_employee_id')->nullable();
            $table->timestamp('conducted_at');
            $this->audit($table);

            $table->index(['tenant_id', 'conducted_at']);
        });

        Schema::create('hse_toolbox_talk_attendances', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('toolbox_talk_id')->constrained('hse_toolbox_talks')->cascadeOnDelete();
            $table->uuid('employee_id');
            $table->string('signature_hash', 64);
            $table->longText('signature_image_base64_or_ref')->nullable();
            $table->timestamp('signed_at');
            $this->audit($table);

            $table->unique(['toolbox_talk_id', 'employee_id']);
            $table->index(['tenant_id', 'toolbox_talk_id']);
        });

        // One row per alert actually sent: the daily scan is idempotent and the 60/30/7 day thresholds fire exactly once.
        Schema::create('hse_expiration_alerts', function (Blueprint $table): void {
            $this->base($table);
            $table->string('subject_type', 24);
            $table->uuid('subject_id');
            $table->integer('threshold_days');
            $table->date('alert_date');
            $table->unsignedInteger('recipients_count')->default(0);
            $this->audit($table);

            $table->unique(['tenant_id', 'subject_type', 'subject_id', 'threshold_days', 'alert_date'], 'uq_hse_expiration_alert');
        });
    }

    public function down(): void
    {
        foreach (['hse_expiration_alerts', 'hse_toolbox_talk_attendances', 'hse_toolbox_talks', 'hse_dvr_documents', 'hse_safety_audits', 'hse_accident_claim_drafts', 'hse_incident_reports', 'hse_ppe_distributions', 'hse_employee_clearance_cache', 'hse_medical_clinical_vault', 'hse_medical_visits', 'hse_safety_certificates', 'hse_requirement_matrices'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
