<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Modules\CRM\Models\Opportunity;
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
    ],
];
