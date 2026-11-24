<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Business Calendars
        if (! Schema::hasTable('business_calendars')) {
            Schema::create('business_calendars', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('timezone', 50)->default('Europe/Rome');
                $table->boolean('work_monday')->default(true);
                $table->boolean('work_tuesday')->default(true);
                $table->boolean('work_wednesday')->default(true);
                $table->boolean('work_thursday')->default(true);
                $table->boolean('work_friday')->default(true);
                $table->boolean('work_saturday')->default(false);
                $table->boolean('work_sunday')->default(false);
                $table->time('work_hours_start')->default('08:30:00');
                $table->time('work_hours_end')->default('17:30:00');
                $table->boolean('is_default')->default(false);
                $table->timestampsTz();

                $table->index(['tenant_id', 'is_default'], 'idx_biz_cal_lookup');
            });
        }

        // 2. Business Calendar Holidays
        if (! Schema::hasTable('business_calendar_holidays')) {
            Schema::create('business_calendar_holidays', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('business_calendar_id')->constrained('business_calendars')->cascadeOnDelete();
                $table->date('holiday_date');
                $table->string('name', 150);
                $table->timestampsTz();

                $table->unique(['business_calendar_id', 'holiday_date'], 'uq_biz_holiday');
            });
        }

        // 3. Evolve existing V1 tables with V2 columns
        Schema::table('workflow_instances', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_instances', 'workflow_definition_version')) {
                $table->integer('workflow_definition_version')->default(1);
            }
            if (! Schema::hasColumn('workflow_instances', 'workflow_definition_snapshot')) {
                $table->jsonb('workflow_definition_snapshot')->default('{}');
            }
            if (! Schema::hasColumn('workflow_instances', 'locked_fiscal_document')) {
                $table->boolean('locked_fiscal_document')->default(false);
            }
        });

        Schema::table('workflow_nodes', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_nodes', 'business_calendar_id')) {
                $table->foreignUuid('business_calendar_id')->nullable()->constrained('business_calendars')->nullOnDelete();
            }
            if (! Schema::hasColumn('workflow_nodes', 'voting_threshold_percentage')) {
                $table->decimal('voting_threshold_percentage', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('workflow_nodes', 'minimum_votes_required')) {
                $table->integer('minimum_votes_required')->nullable();
            }
            if (! Schema::hasColumn('workflow_nodes', 'locks_approvable_on_completion')) {
                $table->boolean('locks_approvable_on_completion')->default(false);
            }
        });

        Schema::table('approval_matrix_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_matrix_rules', 'cost_center_id')) {
                $table->uuid('cost_center_id')->nullable()->index();
            }
        });

        Schema::table('workflow_transitions', function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_transitions', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->index();
            }
        });

        // 4. Approval Tokens (Remote Actions for Teams / Outlook / Email)
        if (! Schema::hasTable('approval_tokens')) {
            Schema::create('approval_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_instance_step_id')->constrained('workflow_instance_steps')->cascadeOnDelete();
                $table->string('token_hash', 64)->unique(); // SHA-256 hash of single-use bearer secret
                $table->string('action_type', 50);          // approve, reject
                $table->uuid('authorized_user_id')->index();
                $table->timestampTz('expires_at')->index();
                $table->timestampTz('used_at')->nullable()->index();
                $table->timestampsTz();

                $table->index(['token_hash', 'used_at', 'expires_at'], 'idx_approval_tokens_active');
            });
        }

        Schema::table('approval_decisions', function (Blueprint $table): void {
            if (! Schema::hasColumn('approval_decisions', 'idempotency_key')) {
                $table->string('idempotency_key', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('approval_decisions', 'approval_token_id')) {
                $table->foreignUuid('approval_token_id')->nullable()->constrained('approval_tokens')->nullOnDelete();
            }
        });

        // 5. Workflow Audit Entries (Legal Forensic Journal)
        if (! Schema::hasTable('workflow_audit_entries')) {
            Schema::create('workflow_audit_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
                $table->foreignUuid('workflow_instance_step_id')->nullable()->constrained('workflow_instance_steps')->nullOnDelete();
                $table->string('event_type', 100)->index();
                $table->jsonb('event_payload')->default('{}');
                $table->uuid('actor_user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('hmac_signature', 64);
                $table->timestampTz('created_at')->useCurrent();

                $table->index(['workflow_instance_id', 'created_at'], 'idx_wf_audit_inst_seq');
                $table->index(['tenant_id', 'event_type'], 'idx_wf_audit_tenant_event');
            });
        }

        // 6. Workflow Webhook Subscriptions (Integration Hub)
        if (! Schema::hasTable('workflow_webhook_subscriptions')) {
            Schema::create('workflow_webhook_subscriptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('name', 150);
                $table->string('target_url', 1000);
                $table->string('secret_key', 255);
                $table->jsonb('subscribed_events')->default('["workflow.completed", "workflow.rejected"]');
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->index(['tenant_id', 'is_active'], 'idx_wf_webhooks_tenant');
            });
        }

        // 7. Workflow Outbound Events
        if (! Schema::hasTable('workflow_outbound_events')) {
            Schema::create('workflow_outbound_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('event_name', 100)->index();
                $table->jsonb('event_payload');
                $table->timestampTz('created_at')->useCurrent();

                $table->index(['created_at'], 'idx_outbound_events_created');
            });
        }

        // 8. Workflow Delivery Attempts
        if (! Schema::hasTable('workflow_delivery_attempts')) {
            Schema::create('workflow_delivery_attempts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('subscription_id')->constrained('workflow_webhook_subscriptions')->cascadeOnDelete();
                $table->foreignUuid('outbound_event_id')->constrained('workflow_outbound_events')->cascadeOnDelete();
                $table->integer('attempt_number')->default(1);
                $table->smallInteger('http_status')->nullable();
                $table->text('response_body')->nullable();
                $table->integer('latency_ms')->nullable();
                $table->string('status', 50)->default('pending'); // pending, successful, failed
                $table->timestampTz('next_retry_at')->nullable();
                $table->timestampsTz();

                $table->index(['status', 'next_retry_at'], 'idx_delivery_attempts_retry');
            });
        }

        // 9. PostgreSQL GIN JSONB Optimizations
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                CREATE INDEX IF NOT EXISTS gin_wf_inst_snapshot ON workflow_instances USING GIN (workflow_definition_snapshot jsonb_path_ops);
                CREATE INDEX IF NOT EXISTS gin_wf_inst_context ON workflow_instances USING GIN (context_snapshot jsonb_path_ops);
                CREATE INDEX IF NOT EXISTS gin_wf_trans_rules ON workflow_transitions USING GIN (condition_rules jsonb_path_ops);
                CREATE INDEX IF NOT EXISTS gin_wf_audit_payload ON workflow_audit_entries USING GIN (event_payload jsonb_path_ops);
                CREATE INDEX IF NOT EXISTS gin_wf_webhook_events ON workflow_webhook_subscriptions USING GIN (subscribed_events jsonb_path_ops);
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_delivery_attempts');
        Schema::dropIfExists('workflow_outbound_events');
        Schema::dropIfExists('workflow_webhook_subscriptions');
        Schema::dropIfExists('workflow_audit_entries');
        Schema::dropIfExists('approval_tokens');
        Schema::dropIfExists('business_calendar_holidays');
        Schema::dropIfExists('business_calendars');
    }
};
