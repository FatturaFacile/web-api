<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Modules\CRM\Models\Opportunity;
use Modules\Documents\Models\DmsDocument;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectChangeOrder;
use Modules\Projects\Models\ProjectCostEntry;

/*
|--------------------------------------------------------------------------
| Records a client may submit to an approval workflow
|--------------------------------------------------------------------------
|
| POST /api/v1/workflows/instances accepts only these approvable types, by alias or by class name, and only from a user
| who can write the module of the record (config/permissions.php). The services of the modules start their own
| workflows in code and are not limited by this list.
|
*/

return [
    'approvables' => [
        'sales_quote' => ['class' => SalesQuote::class, 'module' => 'invoicing'],
        'opportunity' => ['class' => Opportunity::class, 'module' => 'crm'],
        'purchase_order' => ['class' => PurchaseOrder::class, 'module' => 'procurement'],
        'purchase_requisition' => ['class' => PurchaseRequisition::class, 'module' => 'procurement'],
        'passive_invoice' => ['class' => PassiveInvoice::class, 'module' => 'procurement'],
        'inventory_adjustment' => ['class' => InventoryAdjustment::class, 'module' => 'inventory'],
        'project' => ['class' => Project::class, 'module' => 'projects'],
        'project_budget' => ['class' => ProjectBudget::class, 'module' => 'projects'],
        'project_change_order' => ['class' => ProjectChangeOrder::class, 'module' => 'projects'],
        'project_cost_entry' => ['class' => ProjectCostEntry::class, 'module' => 'projects'],
        'leave_request' => ['class' => LeaveRequest::class, 'module' => 'hr'],
        'expense_report' => ['class' => ExpenseReport::class, 'module' => 'expense_management'],
        'dms_document' => ['class' => DmsDocument::class, 'module' => 'documents'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval policy (Mod 006)
    |--------------------------------------------------------------------------
    */

    // Segregation of duties (Mod 097). A definition may override both flags in its `configuration.sod` object.
    'sod' => [
        // The requester can never approve their own request.
        'block_self_approval' => (bool) env('WORKFLOW_SOD_BLOCK_SELF_APPROVAL', false),
        // The same user cannot approve two levels of one workflow.
        'block_multi_level' => (bool) env('WORKFLOW_SOD_BLOCK_MULTI_LEVEL', false),
    ],

    // One-click tokens (email, push, Teams/Slack) of an amount above the threshold (functional currency) need the approver
    // to sign in again (password or 2FA code) on the confirmation page. A threshold of 0 asks for it on every token.
    'token' => [
        'ttl_hours' => (int) env('WORKFLOW_TOKEN_TTL_HOURS', 72),
        'reauth_threshold' => (float) env('WORKFLOW_TOKEN_REAUTH_THRESHOLD', 5000),
    ],

    // Delegation chains (A -> B -> C) are followed this deep.
    'delegation_max_depth' => 3,

    // An approved absence (Mod 074) creates a delegation to the reporting manager for its whole duration.
    'delegation_from_leave' => (bool) env('WORKFLOW_DELEGATION_FROM_LEAVE', false),

    // Post-approval edit: fields whose change after approval restarts the workflow, and the tolerated change of the amount (%).
    'invalidation' => [
        'amount_tolerance_percent' => (float) env('WORKFLOW_AMOUNT_TOLERANCE_PERCENT', 0),
        'fields' => ['amount', 'supplier_id', 'cost_center_id', 'currency'],
    ],

    // Expense reports go through the engine when the tenant has an active definition with this code.
    'engine_subjects' => ['expense_report' => 'EXPENSE_REPORT_APPROVAL'],

    // DMS approval requests run on the engine (workflow generated from the approval template); the legacy rows mirror them.
    'dms_via_engine' => (bool) env('WORKFLOW_DMS_VIA_ENGINE', true),
];
