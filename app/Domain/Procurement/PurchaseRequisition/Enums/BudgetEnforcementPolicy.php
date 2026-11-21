<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Enums;

/**
 * Tenant budget enforcement policy applied when a requisition exceeds residual budget.
 */
enum BudgetEnforcementPolicy: string
{
    case Warning = 'warning';
    case HardBlock = 'hard_block';
}
