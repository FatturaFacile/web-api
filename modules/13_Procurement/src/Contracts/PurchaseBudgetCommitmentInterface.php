<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\Models\PurchaseOrder;

interface PurchaseBudgetCommitmentInterface
{
    public function encumberBudget(PurchaseOrder $order): void;

    public function releaseBudget(PurchaseOrder $order): void;
}
