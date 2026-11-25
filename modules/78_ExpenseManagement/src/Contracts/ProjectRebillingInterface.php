<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Contracts;

use Modules\ExpenseManagement\Models\ExpenseLine;

/**
 * Charges a billable expense line to a project (Mod 081), so it can be re-invoiced to the customer.
 */
interface ProjectRebillingInterface
{
    /**
     * @return string|null the id of the project cost entry created, null when nothing was recorded
     */
    public function recharge(ExpenseLine $line, string $userId): ?string;
}
