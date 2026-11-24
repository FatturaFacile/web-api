<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 096: Privacy & Data Governance Suite (GDPR art. 12/15/17/20/30/33, nLPD art. 12/24/25/28). Additive only.
     * JSON columns keep the migration identical on SQLite and PostgreSQL.
     */
    public function up(): void
    {
        Schema::create('privacy_ropa_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('activity_code', 40);
            $table->string('activity_name');
            $table->text('purpose');
            $table->string('legal_basis', 40);
            $table->json('data_subject_categories')->nullable();
            $table->json('personal_data_categories')->nullable();
            $table->json('recipient_categories')->nullable();
            $table->json('cross_border_transfers')->nullable();
            $table->json('technical_organizational_measures')->nullable();
            $table->unsignedInteger('retention_period_months');
            $table->string('retention_legal_reference', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'activity_code']);
        });

        Schema::create('privacy_dsar_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('request_number', 40);
            $table->string('subject_type', 40);
            $table->string('subject_entity_id', 64)->nullable();
            $table->string('subject_email');
            $table->string('subject_full_name');
            $table->string('request_type', 40);
            $table->timestamp('received_at');
            $table->timestamp('statutory_deadline_at');
            $table->boolean('deadline_extended')->default(false);
            $table->text('extension_justification')->nullable();
            $table->string('status', 50)->default('open');
            $table->timestamp('identity_verified_at')->nullable();
            $table->string('identity_verification_method', 80)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('refusal_legal_justification')->nullable();
            $table->string('export_artifact_path', 500)->nullable();
            $table->string('package_status', 20)->nullable();
            $table->text('audit_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'request_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('privacy_consent_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('subject_type', 40);
            $table->string('subject_entity_id', 64)->nullable();
            $table->string('subject_identifier_hash', 64);
            $table->string('purpose_code', 60);
            $table->boolean('consent_granted');
            $table->string('policy_version', 40);
            $table->timestamp('captured_at');
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('proof_channel', 40);
            $table->string('previous_entry_hash', 64);
            $table->string('entry_hash', 64);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'sequence']);
            $table->index(['tenant_id', 'subject_identifier_hash', 'purpose_code'], 'privacy_consent_subject_purpose_idx');
        });

        Schema::create('privacy_data_breach_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('incident_code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('notification_deadline_72h');
            $table->string('jurisdiction', 30);
            $table->string('severity', 20);
            $table->string('risk_to_rights_and_freedoms', 20);
            $table->unsignedInteger('affected_subjects_count')->default(0);
            $table->json('affected_data_categories')->nullable();
            $table->text('containment_measures')->nullable();
            $table->text('remediation_plan')->nullable();
            $table->timestamp('authority_notified_at')->nullable();
            $table->string('authority_reference_number', 120)->nullable();
            $table->timestamp('subjects_notified_at')->nullable();
            $table->string('status', 30)->default('investigating');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'incident_code']);
        });

        Schema::create('privacy_retention_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('module_code', 40);
            $table->string('entity_class', 200);
            $table->unsignedInteger('retention_days');
            $table->string('action_on_expiry', 40);
            $table->boolean('is_statutory_locked')->default(false);
            $table->string('legal_basis_note', 500)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'module_code']);
        });

        Schema::create('privacy_retention_run_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('evaluated_count')->default(0);
            $table->unsignedInteger('purged_count')->default(0);
            $table->unsignedInteger('anonymized_count')->default(0);
            $table->unsignedInteger('statutory_skipped_count')->default(0);
            $table->json('details')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        foreach (['privacy_retention_run_logs', 'privacy_retention_rules', 'privacy_data_breach_incidents', 'privacy_consent_ledger', 'privacy_dsar_requests', 'privacy_ropa_records'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
