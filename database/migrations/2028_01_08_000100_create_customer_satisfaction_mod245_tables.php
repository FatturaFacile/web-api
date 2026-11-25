<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 245 — automated NPS / CSAT / CES customer satisfaction surveys, closed-loop detractor recovery and ISO 9001 § 9.1.2 reporting.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL. Every table carries the tenant, soft deletes and the audit columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->surveyTemplates();
        $this->surveyDispatches();
        $this->surveyResponses();
        $this->detractorRecoveryCases();
        $this->isoReports();
        $this->optOuts();
    }

    public function down(): void
    {
        foreach (['survey_opt_outs', 'iso_9001_satisfaction_reports', 'detractor_recovery_cases', 'survey_responses', 'survey_dispatches', 'survey_templates'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function surveyTemplates(): void
    {
        if (Schema::hasTable('survey_templates')) {
            return;
        }

        Schema::create('survey_templates', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 60);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('survey_type', 10); // nps, csat, ces
            $table->string('trigger_event', 40); // ticket_closed, order_delivered, field_intervention_signed, onboarding_completed, semi_annual_active_client
            $table->jsonb('trigger_filters')->nullable(); // department, branch, product line, customer tier, minimum criteria
            $table->unsignedSmallInteger('priority')->default(100);
            $table->jsonb('channels'); // email, sms, whatsapp
            $table->unsignedInteger('delay_hours')->default(0);
            $table->unsignedSmallInteger('cooldown_days')->default(60);
            $table->unsignedSmallInteger('detractor_threshold')->nullable();
            $table->unsignedSmallInteger('sla_hours')->default(24);
            $table->boolean('allow_voice_comment')->default(false);
            $table->jsonb('messages')->nullable(); // subject, body, one-click label per locale
            $table->jsonb('follow_up_config')->nullable(); // dynamic prompt per score band and locale
            $table->jsonb('dimensions')->nullable(); // default department / product line / branch
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'trigger_event', 'is_active']);
        });
    }

    private function surveyDispatches(): void
    {
        if (Schema::hasTable('survey_dispatches')) {
            return;
        }

        Schema::create('survey_dispatches', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('survey_template_id')->constrained('survey_templates')->cascadeOnDelete();
            $table->uuid('customer_id')->index();
            $table->uuid('contact_id')->nullable();
            $table->string('trigger_event', 40);
            $table->string('source_entity_type', 60)->nullable();
            $table->uuid('source_entity_id')->nullable();
            $table->string('channel', 20);
            $table->string('locale', 10)->default('en');
            $table->string('dispatch_token', 64)->unique();
            $table->string('status', 20)->default('scheduled')->index();
            $table->string('throttle_reason', 30)->nullable();
            $table->dateTime('scheduled_for')->nullable()->index();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('responded_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'customer_id', 'created_at']);
            $table->index(['tenant_id', 'source_entity_type', 'source_entity_id']);
        });
    }

    private function surveyResponses(): void
    {
        if (Schema::hasTable('survey_responses')) {
            return;
        }

        Schema::create('survey_responses', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('survey_dispatch_id')->constrained('survey_dispatches')->cascadeOnDelete();
            $table->foreignUuid('survey_template_id')->constrained('survey_templates')->cascadeOnDelete();
            $table->uuid('customer_id')->index();
            $table->uuid('contact_id')->nullable();
            $table->string('survey_type', 10);
            $table->unsignedSmallInteger('score');
            $table->string('normalized_band', 20);
            $table->boolean('is_detractor')->default(false)->index();
            $table->text('comment_text')->nullable();
            $table->string('voice_attachment_path', 255)->nullable();
            $table->unsignedInteger('voice_duration_seconds')->nullable();
            $table->text('voice_transcript')->nullable();
            $table->decimal('sentiment_score', 5, 2)->nullable();
            $table->string('sentiment_label', 10)->nullable();
            $table->jsonb('sentiment_topics')->nullable();
            $table->string('department_code', 60)->nullable()->index();
            $table->uuid('technician_id')->nullable()->index();
            $table->string('product_line_code', 60)->nullable()->index();
            $table->string('branch_code', 60)->nullable()->index();
            $table->string('submitted_via', 20);
            $table->string('locale', 10)->nullable();
            $table->jsonb('metadata')->nullable(); // IP, user agent, channel
            $table->dateTime('initial_clicked_at');
            $table->dateTime('comment_updated_at')->nullable();
            $this->audit($table);
            $table->unique('survey_dispatch_id');
            $table->index(['tenant_id', 'survey_type', 'initial_clicked_at']);
        });
    }

    private function detractorRecoveryCases(): void
    {
        if (Schema::hasTable('detractor_recovery_cases')) {
            return;
        }

        Schema::create('detractor_recovery_cases', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('survey_response_id')->constrained('survey_responses')->cascadeOnDelete();
            $table->uuid('customer_id')->index();
            $table->uuid('assigned_account_manager_id')->nullable()->index();
            $table->uuid('assigned_support_lead_id')->nullable()->index();
            $table->uuid('crm_task_id')->nullable();
            $table->string('priority', 10)->default('high');
            $table->string('status', 20)->default('open')->index();
            $table->dateTime('sla_due_at')->index();
            $table->dateTime('first_contacted_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->boolean('churn_risk_updated')->default(false);
            $table->string('previous_churn_risk', 20)->nullable();
            $table->string('new_churn_risk', 20)->nullable();
            $table->text('resolution_summary')->nullable();
            $table->string('root_cause_code', 60)->nullable();
            $this->audit($table);
            $table->unique('survey_response_id');
            $table->index(['tenant_id', 'status', 'sla_due_at']);
        });
    }

    private function isoReports(): void
    {
        if (Schema::hasTable('iso_9001_satisfaction_reports')) {
            return;
        }

        Schema::create('iso_9001_satisfaction_reports', function (Blueprint $table): void {
            $this->base($table);
            $table->string('title', 200);
            $table->date('period_from');
            $table->date('period_to');
            $table->string('group_by', 20)->default('overall');
            $table->jsonb('filters')->nullable();
            $table->jsonb('metrics_snapshot');
            $table->text('management_review_notes')->nullable();
            $table->uuid('generated_by_user_id')->nullable();
            $table->string('exported_format', 10)->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'period_from', 'period_to']);
        });
    }

    private function optOuts(): void
    {
        if (Schema::hasTable('survey_opt_outs')) {
            return;
        }

        Schema::create('survey_opt_outs', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('customer_id')->index();
            $table->string('channel', 20)->nullable(); // null: every channel
            $table->string('reason', 120)->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'customer_id']);
        });
    }
};
