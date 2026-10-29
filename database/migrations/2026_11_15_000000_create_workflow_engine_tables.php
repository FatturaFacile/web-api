<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Workflow Definitions (Versioned Blueprint)
        if (! Schema::hasTable('workflow_definitions')) {
            Schema::create('workflow_definitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 100);
                $table->string('name', 255);
                $table->text('description')->nullable();
                $table->string('target_entity_type', 255);
                $table->integer('version')->default(1);
                $table->string('status', 50)->default('draft'); // draft, published, archived
                $table->boolean('is_active')->default(false);
                $table->jsonb('configuration')->default('{}');
                $table->timestampTz('published_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code', 'version'], 'uq_workflow_defs_code_version');
                $table->index(['tenant_id', 'target_entity_type', 'is_active'], 'idx_workflow_defs_lookup');
            });
        }

        // 2. Workflow Nodes
        if (! Schema::hasTable('workflow_nodes')) {
            Schema::create('workflow_nodes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
                $table->string('node_key', 100);
                $table->string('name', 255);
                $table->string('node_type', 50); // start, approval, conditional, fork, join, notification, end
                $table->string('approval_type', 50)->nullable(); // single, and_parallel, or_parallel, majority, supermajority, unanimous
                $table->string('assignee_type', 50)->nullable(); // direct_user, role, reporting_manager, matrix_lookup
                $table->string('assignee_id', 255)->nullable();
                $table->integer('timeout_hours')->nullable()->default(48);
                $table->uuid('escalation_rule_id')->nullable();
                $table->jsonb('ui_metadata')->default('{}');
                $table->timestampsTz();

                $table->unique(['workflow_definition_id', 'node_key'], 'uq_workflow_node_key');
                $table->index(['tenant_id', 'workflow_definition_id'], 'idx_workflow_nodes_tenant');
            });
        }

        // 3. Workflow Transitions
        if (! Schema::hasTable('workflow_transitions')) {
            Schema::create('workflow_transitions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
                $table->foreignUuid('from_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
                $table->foreignUuid('to_node_id')->constrained('workflow_nodes')->cascadeOnDelete();
                $table->string('action_trigger', 50)->default('approve'); // approve, reject, timeout, condition_true, condition_false
                $table->jsonb('condition_rules')->default('[]');
                $table->integer('priority')->default(1);
                $table->timestampsTz();

                $table->index(['from_node_id', 'action_trigger', 'priority'], 'idx_workflow_trans_nav');
            });
        }

        // 4. Approval Matrices
        if (! Schema::hasTable('approval_matrixes')) {
            Schema::create('approval_matrixes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->string('code', 100);
                $table->string('name', 255);
                $table->string('target_entity_type', 255);
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->unique(['tenant_id', 'code'], 'uq_approval_matrix_code');
                $table->index(['tenant_id', 'target_entity_type', 'is_active'], 'idx_approval_matrixes_tenant');
            });
        }

        // 5. Approval Matrix Rules
        if (! Schema::hasTable('approval_matrix_rules')) {
            Schema::create('approval_matrix_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('approval_matrix_id')->constrained('approval_matrixes')->cascadeOnDelete();
                $table->string('field_name', 100);
                $table->string('operator', 20); // =, !=, >, >=, <, <=, in, between
                $table->text('value_string')->nullable();
                $table->decimal('value_numeric', 18, 4)->nullable();
                $table->jsonb('value_json')->nullable();
                $table->timestampsTz();

                $table->index(['approval_matrix_id'], 'idx_matrix_rules_parent');
            });
        }

        // 6. Approval Matrix Tiers
        if (! Schema::hasTable('approval_matrix_tiers')) {
            Schema::create('approval_matrix_tiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('approval_matrix_id')->constrained('approval_matrixes')->cascadeOnDelete();
                $table->integer('tier_level')->default(1);
                $table->string('approver_type', 50); // user, role, reporting_manager, matrix_approver
                $table->string('approver_value', 255);
                $table->string('approval_type', 50)->default('or_parallel');
                $table->decimal('threshold_min', 18, 4)->nullable();
                $table->decimal('threshold_max', 18, 4)->nullable();
                $table->timestampsTz();

                $table->index(['approval_matrix_id', 'tier_level'], 'idx_matrix_tiers_sort');
            });
        }

        // 7. Workflow Delegations
        if (! Schema::hasTable('workflow_delegations')) {
            Schema::create('workflow_delegations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('delegator_user_id')->index();
                $table->uuid('delegate_user_id')->index();
                $table->string('scope', 50)->default('all');
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at');
                $table->text('reason')->nullable();
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['tenant_id', 'delegator_user_id', 'is_active', 'starts_at', 'ends_at'], 'idx_wf_delegations_active');
            });
        }

        // 8. Workflow Escalation Rules
        if (! Schema::hasTable('workflow_escalation_rules')) {
            Schema::create('workflow_escalation_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('name', 255);
                $table->integer('reminder_hours')->default(24);
                $table->integer('escalation_hours')->default(48);
                $table->string('escalate_to_type', 50); // reporting_manager, specific_role, specific_user
                $table->string('escalate_to_id', 255)->nullable();
                $table->string('auto_action', 50)->nullable(); // none, auto_approve, auto_reject
                $table->timestampsTz();
            });
        }

        // 9. Workflow Instances (Runtime Execution State Machine)
        if (! Schema::hasTable('workflow_instances')) {
            Schema::create('workflow_instances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('workflow_definition_id')->constrained('workflow_definitions')->restrictOnDelete();
                $table->string('approvable_type', 255);
                $table->uuid('approvable_id');
                $table->uuid('current_node_id')->nullable();
                $table->string('status', 50)->default('pending'); // draft, pending, approved, rejected, cancelled, expired
                $table->jsonb('context_snapshot')->default('{}');
                $table->timestampTz('started_at')->useCurrent();
                $table->timestampTz('completed_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestampsTz();
                $table->softDeletesTz();

                $table->index(['tenant_id', 'approvable_type', 'approvable_id'], 'idx_workflow_inst_morph');
                $table->index(['tenant_id', 'status'], 'idx_workflow_inst_status');
            });
        }

        // 10. Workflow Instance Steps
        if (! Schema::hasTable('workflow_instance_steps')) {
            Schema::create('workflow_instance_steps', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
                $table->foreignUuid('workflow_node_id')->constrained('workflow_nodes')->restrictOnDelete();
                $table->uuid('assigned_user_id')->nullable()->index();
                $table->string('assigned_role', 100)->nullable();
                $table->string('status', 50)->default('pending'); // pending, approved, rejected, delegated, skipped, timed_out
                $table->integer('tier_level')->default(1);
                $table->timestampTz('due_at')->nullable();
                $table->timestampTz('reminded_at')->nullable();
                $table->timestampTz('escalated_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'assigned_user_id', 'status'], 'idx_instance_steps_pending');
                $table->index(['status', 'due_at'], 'idx_instance_steps_due');
            });
        }

        // 11. Approval Decisions
        if (! Schema::hasTable('approval_decisions')) {
            Schema::create('approval_decisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_instance_step_id')->constrained('workflow_instance_steps')->cascadeOnDelete();
                $table->uuid('user_id')->index();
                $table->uuid('effective_user_id')->index();
                $table->string('decision', 50); // approved, rejected, abstained
                $table->text('comment')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->jsonb('signature_payload')->nullable();
                $table->timestampTz('decided_at')->useCurrent();
                $table->timestampsTz();

                $table->index(['workflow_instance_step_id'], 'idx_approval_decisions_step');
            });
        }

        // 12. Workflow Comments
        if (! Schema::hasTable('workflow_comments')) {
            Schema::create('workflow_comments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->foreignUuid('workflow_instance_id')->constrained('workflow_instances')->cascadeOnDelete();
                $table->foreignUuid('workflow_instance_step_id')->nullable()->constrained('workflow_instance_steps')->nullOnDelete();
                $table->uuid('user_id')->index();
                $table->text('comment');
                $table->boolean('is_internal_only')->default(false);
                $table->timestampsTz();

                $table->index(['workflow_instance_id', 'created_at'], 'idx_wf_comments_instance');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_comments');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('workflow_instance_steps');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_escalation_rules');
        Schema::dropIfExists('workflow_delegations');
        Schema::dropIfExists('approval_matrix_tiers');
        Schema::dropIfExists('approval_matrix_rules');
        Schema::dropIfExists('approval_matrixes');
        Schema::dropIfExists('workflow_transitions');
        Schema::dropIfExists('workflow_nodes');
        Schema::dropIfExists('workflow_definitions');
    }
};
