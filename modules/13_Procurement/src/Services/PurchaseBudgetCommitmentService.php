<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Illuminate\Support\Facades\DB;
use Modules\Procurement\Contracts\PurchaseBudgetCommitmentInterface;
use Modules\Procurement\Models\PurchaseOrder;

final class PurchaseBudgetCommitmentService implements PurchaseBudgetCommitmentInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger
    ) {}

    public function encumberBudget(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = PurchaseOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->is_budget_encumbered) {
                return;
            }

            $lockedOrder->update([
                'is_budget_encumbered' => true,
                'budget_committed_amount' => $lockedOrder->total_amount,
            ]);

            $this->auditLedger->record(
                action: 'BUDGET_ENCUMBERED',
                model: $lockedOrder,
                payloadBefore: ['is_budget_encumbered' => false],
                payloadAfter: ['is_budget_encumbered' => true, 'amount' => $lockedOrder->total_amount]
            );
        });
    }

    public function releaseBudget(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = PurchaseOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! $lockedOrder->is_budget_encumbered) {
                return;
            }

            $lockedOrder->update([
                'is_budget_encumbered' => false,
                'budget_committed_amount' => 0.00,
            ]);

            $this->auditLedger->record(
                action: 'BUDGET_RELEASED',
                model: $lockedOrder,
                payloadBefore: ['is_budget_encumbered' => true],
                payloadAfter: ['is_budget_encumbered' => false, 'amount' => 0.00]
            );
        });
    }
}
