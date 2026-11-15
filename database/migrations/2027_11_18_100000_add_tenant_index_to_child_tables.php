<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'budget_approvals', 'budget_drivers', 'budget_lines', 'comm_campaign_recipients',
        'comm_conversation_messages', 'contract_clause_fallbacks', 'contract_clause_versions',
        'contract_comments', 'contract_document_versions', 'contract_negotiation_rounds',
        'contract_signature_events', 'contract_signature_requests', 'contract_signers',
        'contract_template_clauses', 'credit_dunning_steps', 'crm_automation_steps',
        'dms_approval_decisions', 'dms_approval_template_steps', 'planning_approvals',
        'planning_capacity_constraints', 'tl_comp_budget_pools', 'tl_comp_guidelines',
        'tl_learning_path_courses', 'tl_learning_paths', 'tl_survey_answers',
        'tl_survey_participants', 'tl_survey_questions', 'tl_survey_responses',
    ];

    /**
     * Gives every listed table a tenant_id index so tenant-scoped reads never scan the whole table.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            if (Schema::hasIndex($tableName, ['tenant_id'])) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->index('tenant_id', $tableName.'_tenant_id_idx');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasIndex($tableName, $tableName.'_tenant_id_idx')) {
                Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                    $table->dropIndex($tableName.'_tenant_id_idx');
                });
            }
        }
    }
};
