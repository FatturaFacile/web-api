<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * The budget spend lock seen from purchasing. Before a requisition or an order is created, submitted or approved the
 * budget control checks that the spend it asks for fits the budget of its cost centre and project; the commitment it
 * takes is released when the document is cancelled. Without a budgeting module the control lets everything through.
 */
interface SpendControlInterface
{
    public const string StageCreate = 'create';

    public const string StageSubmit = 'submit';

    public const string StageApprove = 'approve';

    /**
     * @param  self::Stage*  $stage
     *
     * @throws ProcurementRuleViolation when the budget does not allow the spend and no override covers it
     */
    public function check(PurchaseOrder|PurchaseRequisition $document, string $stage): void;

    /**
     * The document is cancelled: its commitment stops counting against the budget.
     */
    public function release(PurchaseOrder|PurchaseRequisition $document): void;
}
