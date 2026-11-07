<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowNode;
use Modules\Workflow\Models\WorkflowTransition;

/**
 * Default approval definitions for the workflow codes the modules start (grep "workflowCode:" in modules/).
 *
 * Every default is the simplest valid graph: start -> one approval step -> end. The step is assigned to the "admin"
 * role, which the engine also grants to the owner of the company; a single decision closes it, approving moves to the
 * end node and rejecting rejects the instance. A code that already has a definition in the tenant (any version or
 * status) is left alone, so running the action again or after a customisation changes nothing.
 */
final class SeedDefaultWorkflowDefinitionsAction
{
    /**
     * Workflow code => [name, target entity type].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array Defaults = [
        'SALES_QUOTE_APPROVAL' => ['Sales quote approval', 'sales_quote'],
        'HIGH_VALUE_SALES_QUOTE_APPROVAL' => ['High-value sales quote approval', 'sales_quote'],
        'HIGH_VALUE_OPPORTUNITY_APPROVAL' => ['High-value opportunity approval', 'crm_opportunity'],
        'LARGE_DISCOUNT_APPROVAL' => ['Large discount approval', 'crm_opportunity'],
        'STRATEGIC_ACCOUNT_APPROVAL' => ['Strategic account approval', 'crm_opportunity'],
        'OPPORTUNITY_CLOSURE_EXCEPTION_APPROVAL' => ['Opportunity closure exception approval', 'crm_opportunity'],
        'PURCHASE_REQUISITION_APPROVAL' => ['Purchase requisition approval', 'purchase_requisition'],
        'PURCHASE_ORDER_APPROVAL' => ['Purchase order approval', 'purchase_order'],
        'HIGH_VALUE_PURCHASE_APPROVAL' => ['High-value purchase order approval', 'purchase_order'],
        'PASSIVE_INVOICE_APPROVAL' => ['Supplier invoice approval', 'passive_invoice'],
        'THREE_WAY_MATCH_DISCREPANCY_APPROVAL' => ['Three-way match discrepancy approval', 'three_way_match'],
        'INVENTORY_WRITE_OFF_APPROVAL' => ['Inventory write-off approval', 'inventory_adjustment'],
        'PROJECT_BUDGET_APPROVAL' => ['Project budget approval', 'project'],
        'PROJECT_CHANGE_REQUEST_APPROVAL' => ['Project change request approval', 'project'],
        'PROJECT_COST_VARIANCE_APPROVAL' => ['Project cost variance approval', 'project'],
        'PROJECT_EXPENSE_APPROVAL' => ['Project expense approval', 'project_expense'],
        'PROJECT_CLOSURE_APPROVAL' => ['Project closure approval', 'project'],
        'ASSET_CAPITALIZATION_APPROVAL' => ['Asset capitalization approval', 'fixed_asset'],
        'ASSET_TRANSFER_APPROVAL' => ['Asset transfer approval', 'fixed_asset'],
        'ASSET_REVALUATION_APPROVAL' => ['Asset revaluation approval', 'fixed_asset'],
        'ASSET_IMPAIRMENT_APPROVAL' => ['Asset impairment approval', 'fixed_asset'],
        'ASSET_DISPOSAL_APPROVAL' => ['Asset disposal approval', 'fixed_asset'],
        'LARGE_MAINTENANCE_APPROVAL' => ['Large maintenance approval', 'maintenance_work_order'],
        'HR_ONBOARDING_APPROVAL' => ['Employee onboarding approval', 'employee'],
        'HR_PROMOTION_APPROVAL' => ['Employee promotion approval', 'employee'],
        'HR_TRANSFER_APPROVAL' => ['Employee transfer approval', 'employee'],
        'HR_TERMINATION_OFFBOARDING' => ['Employee termination approval', 'employee'],
        'HR_LEAVE_APPROVAL' => ['Leave request approval', 'leave_request'],
        'HR_PERFORMANCE_REVIEW_APPROVAL' => ['Performance review approval', 'performance_review'],
        'HR_TRAINING_APPROVAL' => ['Training approval', 'training_enrollment'],
        'JOURNAL_ENTRY_APPROVAL' => ['Journal entry approval', 'journal_entry'],
        'PERIOD_CLOSING_APPROVAL' => ['Period closing approval', 'fiscal_period'],
    ];

    public const string ApproverRole = 'admin';

    /**
     * @param  list<string>|null  $codes  only these codes (all defaults when null)
     * @return list<string> the codes that received a definition
     */
    public function execute(string $tenantId, ?array $codes = null): array
    {
        $created = [];

        foreach (self::Defaults as $code => [$name, $target]) {
            if ($codes !== null && ! in_array($code, $codes, true)) {
                continue;
            }

            $exists = WorkflowDefinition::withoutGlobalScopes()->withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::transaction(fn () => $this->createDefinition($tenantId, $code, $name, $target));
            $created[] = $code;
        }

        return $created;
    }

    private function createDefinition(string $tenantId, string $code, string $name, string $target): void
    {
        $definition = WorkflowDefinition::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => 'Default single-step approval by the owner or an administrator of the company.',
            'target_entity_type' => $target,
            'version' => 1,
            'status' => 'published',
            'is_active' => true,
            'configuration' => ['seeded_default' => true],
            'published_at' => now(),
        ]);

        $node = fn (string $key, string $label, string $type, array $extra = []): WorkflowNode => WorkflowNode::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'workflow_definition_id' => $definition->id,
            'node_key' => $key,
            'name' => $label,
            'node_type' => $type,
            ...$extra,
        ]);

        $start = $node('start', 'Start', 'start');
        $approval = $node('approval', 'Approval by owner or administrator', 'approval', [
            'approval_type' => 'single',
            'assignee_type' => 'role',
            'assignee_id' => self::ApproverRole,
            'timeout_hours' => 48,
        ]);
        $end = $node('end', 'Approved', 'end');

        foreach ([[$start, $approval, 'start'], [$approval, $end, 'approved']] as [$from, $to, $trigger]) {
            WorkflowTransition::withoutGlobalScopes()->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'workflow_definition_id' => $definition->id,
                'from_node_id' => $from->id,
                'to_node_id' => $to->id,
                'action_trigger' => $trigger,
                'priority' => 1,
            ]);
        }
    }
}
