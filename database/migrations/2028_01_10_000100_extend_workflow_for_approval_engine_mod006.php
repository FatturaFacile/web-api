<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mod 006: columns the configurable approval engine needs on top of the baseline tables. Every column is nullable or
     * defaulted and every statement is guarded, so the migration is safe to re-run and portable on SQLite and PostgreSQL.
     */
    public function up(): void
    {
        Schema::table('workflow_definitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_definitions', 'lock_version')) {
                // Optimistic lock of the draft editor (If-Match header); `version` stays the business version.
                $table->unsignedInteger('lock_version')->default(1);
            }
        });

        Schema::table('approval_matrixes', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_matrixes', 'priority')) {
                $table->unsignedInteger('priority')->default(100);
            }
            if (! Schema::hasColumn('approval_matrixes', 'currency')) {
                $table->string('currency', 3)->nullable();
            }
            if (! Schema::hasColumn('approval_matrixes', 'auto_approve_below')) {
                $table->decimal('auto_approve_below', 18, 4)->nullable();
            }
            if (! Schema::hasColumn('approval_matrixes', 'is_default')) {
                $table->boolean('is_default')->default(false);
            }
            if (! Schema::hasColumn('approval_matrixes', 'fallback_approver_type')) {
                $table->string('fallback_approver_type', 50)->nullable();
            }
            if (! Schema::hasColumn('approval_matrixes', 'fallback_approver_value')) {
                $table->string('fallback_approver_value', 255)->nullable();
            }
        });

        Schema::table('approval_matrix_tiers', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_matrix_tiers', 'threshold_min_inclusive')) {
                // "Above 5'000" (exclusive) versus "from 5'000" (inclusive); existing tiers keep the inclusive meaning.
                $table->boolean('threshold_min_inclusive')->default(true);
            }
        });

        Schema::table('workflow_delegations', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_delegations', 'document_types')) {
                $table->json('document_types')->nullable();
            }
            if (! Schema::hasColumn('workflow_delegations', 'amount_cap')) {
                $table->decimal('amount_cap', 18, 4)->nullable();
            }
            if (! Schema::hasColumn('workflow_delegations', 'source_type')) {
                $table->string('source_type', 50)->nullable();
            }
            if (! Schema::hasColumn('workflow_delegations', 'source_id')) {
                $table->uuid('source_id')->nullable()->index();
            }
        });

        Schema::table('workflow_instances', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_instances', 'blocked_reason')) {
                $table->text('blocked_reason')->nullable();
            }
            if (! Schema::hasColumn('workflow_instances', 'blocked_at')) {
                $table->timestampTz('blocked_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_instances', 'withdrawn_at')) {
                $table->timestampTz('withdrawn_at')->nullable();
            }
            if (! Schema::hasColumn('workflow_instances', 'closing_reason')) {
                $table->text('closing_reason')->nullable();
            }
            if (! Schema::hasColumn('workflow_instances', 'approved_snapshot')) {
                // Key attributes of the document at the moment it was approved: a later edit beyond tolerance restarts the approval.
                $table->json('approved_snapshot')->nullable();
            }
            if (! Schema::hasColumn('workflow_instances', 'restarted_from_id')) {
                $table->uuid('restarted_from_id')->nullable();
            }
        });

        Schema::table('approval_tokens', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_tokens', 'requires_reauth')) {
                $table->boolean('requires_reauth')->default(false);
            }
            if (! Schema::hasColumn('approval_tokens', 'amount')) {
                $table->decimal('amount', 18, 4)->nullable();
            }
            if (! Schema::hasColumn('approval_tokens', 'channel')) {
                $table->string('channel', 30)->default('email');
            }
        });

        Schema::table('business_calendar_holidays', function (Blueprint $table): void {
            if (! Schema::hasColumn('business_calendar_holidays', 'holiday_type')) {
                $table->string('holiday_type', 30)->default('national');
            }
            if (! Schema::hasColumn('business_calendar_holidays', 'region')) {
                $table->string('region', 10)->nullable();
            }
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenant_user', 'approval_limit')) {
                // ABAC signing limit (functional currency of the tenant); null means unlimited.
                $table->decimal('approval_limit', 18, 4)->nullable();
            }
        });

        Schema::table('dms_approval_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('dms_approval_requests', 'workflow_instance_id')) {
                $table->uuid('workflow_instance_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        // Additive, defaulted columns are kept on rollback: dropping them would lose approval evidence.
    }
};
