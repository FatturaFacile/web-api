<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operational GDPR: workflow of the data subject requests (identity check, progress, rejection, history), retention
     * policies with their run log, and the fields of the breach register needed to track the 72 hour notification.
     * Additive only: nothing existing is dropped or renamed.
     */
    public function up(): void
    {
        Schema::table('privacy_data_subject_requests', function (Blueprint $table): void {
            $table->timestamp('identity_verified_at')->nullable();
            $table->uuid('identity_verified_by')->nullable();
            $table->string('identity_verification_method', 60)->nullable();
            $table->timestamp('in_progress_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedSmallInteger('deadline_days')->nullable();
            $table->string('requester_reference', 120)->nullable();
            $table->text('response_summary')->nullable();
        });

        Schema::create('privacy_dsr_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('request_id')->constrained('privacy_data_subject_requests')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'request_id']);
        });

        Schema::create('privacy_retention_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entry_key', 120);
            $table->unsignedInteger('retention_days');
            $table->string('action', 20)->default('anonymise');
            $table->boolean('is_active')->default(true);
            $table->text('legal_basis_note')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entry_key']);
        });

        Schema::create('privacy_retention_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('policy_id')->nullable();
            $table->string('entry_key', 120);
            $table->boolean('dry_run')->default(true);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->string('status', 20)->default('completed');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('details')->nullable();
            $table->uuid('triggered_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entry_key']);
        });

        Schema::table('privacy_data_breaches', function (Blueprint $table): void {
            $table->text('description')->nullable();
            $table->unsignedInteger('affected_subjects_count')->nullable();
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('delay_reason')->nullable();
            $table->text('authority_not_required_reason')->nullable();
            $table->uuid('reported_by')->nullable();
        });

        if (Schema::hasTable('crm_leads')) {
            Schema::table('crm_leads', function (Blueprint $table): void {
                if (! Schema::hasColumn('crm_leads', 'processing_restricted_at')) {
                    $table->timestamp('processing_restricted_at')->nullable();
                }
                if (! Schema::hasColumn('crm_leads', 'anonymized_at')) {
                    $table->timestamp('anonymized_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crm_leads')) {
            Schema::table('crm_leads', function (Blueprint $table): void {
                foreach (['processing_restricted_at', 'anonymized_at'] as $column) {
                    if (Schema::hasColumn('crm_leads', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::table('privacy_data_breaches', function (Blueprint $table): void {
            $table->dropColumn(['description', 'affected_subjects_count', 'contained_at', 'closed_at', 'delay_reason', 'authority_not_required_reason', 'reported_by']);
        });

        Schema::dropIfExists('privacy_retention_runs');
        Schema::dropIfExists('privacy_retention_policies');
        Schema::dropIfExists('privacy_dsr_events');

        Schema::table('privacy_data_subject_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'identity_verified_at', 'identity_verified_by', 'identity_verification_method', 'in_progress_at', 'rejected_at',
                'rejection_reason', 'deadline_days', 'requester_reference', 'response_summary',
            ]);
        });
    }
};
