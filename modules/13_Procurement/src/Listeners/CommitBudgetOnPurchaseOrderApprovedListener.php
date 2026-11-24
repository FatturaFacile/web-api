<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Modules\Procurement\Contracts\PurchaseBudgetCommitmentInterface;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Models\PurchaseOrder;

final class CommitBudgetOnPurchaseOrderApprovedListener
{
    public function __construct(
        private readonly PurchaseBudgetCommitmentInterface $budgetService
    ) {}

    public function handle(PurchaseOrderApprovedEvent $event): void
    {
        $order = PurchaseOrder::find($event->purchaseOrderId);
        if ($order !== null) {
            $this->budgetService->encumberBudget($order);
        }
    }
}
