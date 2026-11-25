<?php

declare(strict_types=1);

namespace Modules\Budgeting\Integration;

use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Services\CommitmentService;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * Keeps the commitments in line with purchasing. A requisition commits the budget when it is approved; its order takes
 * the commitment over when the order is approved (the requisition stops counting for that amount, so nothing is
 * counted twice); the commitment of an order is consumed as the goods are received or invoiced and released when the
 * order is cancelled. Also used by the reconcile command to repair any drift.
 */
final readonly class ProcurementCommitmentSync
{
    /** Order statuses that commit the budget. */
    private const array CommittingOrderStatuses = ['approved', 'sent', 'confirmed', 'partially_received', 'received', 'closed'];

    public function __construct(private CommitmentService $commitments, private ProcurementSpendControl $control) {}

    public function onRequisitionApproved(PurchaseRequisitionApprovedEvent $event): void
    {
        $requisition = PurchaseRequisition::withoutGlobalScopes()->find($event->requisitionId);

        if ($requisition !== null) {
            $this->syncRequisition($requisition);
        }
    }

    public function onOrderApproved(PurchaseOrderApprovedEvent $event): void
    {
        $this->syncOrderById($event->purchaseOrderId);
    }

    public function onGoodsReceived(GoodsReceivedEvent $event): void
    {
        $this->syncOrderById($event->purchaseOrderId);
    }

    public function onOrderCancelled(PurchaseOrderCancelledEvent $event): void
    {
        $order = PurchaseOrder::withoutGlobalScopes()->find($event->purchaseOrderId);

        if ($order !== null) {
            $this->control->release($order);
        }
    }

    public function syncRequisition(PurchaseRequisition $requisition): void
    {
        $tenantId = (string) $requisition->tenant_id;
        $type = BudgetCommitment::Requisition;

        if (! in_array($requisition->status, [PurchaseRequisitionStatus::Approved->value, PurchaseRequisitionStatus::Sourcing->value, PurchaseRequisitionStatus::Ordered->value], true)) {
            $this->commitments->release($tenantId, $type, (string) $requisition->id, 'not_approved');

            return;
        }

        $this->commitments->sync([
            'tenant_id' => $tenantId,
            'source_type' => $type,
            'source_id' => (string) $requisition->id,
            'source_number' => (string) $requisition->requisition_number,
            'parent_source_id' => null,
            'account_id' => null,
            'cost_center_id' => $requisition->cost_center_id !== null ? (string) $requisition->cost_center_id : null,
            'project_id' => $requisition->project_id !== null ? (string) $requisition->project_id : null,
            'date' => $this->control->documentDate($requisition),
            'currency' => (string) $requisition->currency,
            'amount' => $this->control->netAmount($requisition),
            'consumed' => '0.00',
        ]);

        $this->commitments->reconcileRequisition($tenantId, (string) $requisition->id);
    }

    public function syncOrder(PurchaseOrder $order): void
    {
        $tenantId = (string) $order->tenant_id;

        if ($order->status === 'cancelled') {
            $this->control->release($order);

            return;
        }

        if (! in_array($order->status, self::CommittingOrderStatuses, true)) {
            return;
        }

        $amount = $this->control->netAmount($order);
        $consumed = $order->status === 'closed' ? $amount : $this->consumedAmount($order, $amount);

        $this->commitments->sync([
            'tenant_id' => $tenantId,
            'source_type' => BudgetCommitment::PurchaseOrder,
            'source_id' => (string) $order->id,
            'source_number' => (string) ($order->po_number ?? $order->order_number),
            'parent_source_id' => $order->purchase_requisition_id !== null ? (string) $order->purchase_requisition_id : null,
            'account_id' => null,
            'cost_center_id' => $order->cost_center_id !== null ? (string) $order->cost_center_id : null,
            'project_id' => $order->project_id !== null ? (string) $order->project_id : null,
            'date' => $this->control->documentDate($order),
            'currency' => (string) $order->currency,
            'amount' => $amount,
            'consumed' => $consumed,
            'user_id' => $order->approved_by !== null ? (string) $order->approved_by : null,
        ]);

        if ($order->purchase_requisition_id !== null) {
            $this->commitments->reconcileRequisition($tenantId, (string) $order->purchase_requisition_id);
        }
    }

    /**
     * Every approved requisition and committing order of the company, and the release of the commitments of documents
     * that were cancelled without the event reaching the budget.
     *
     * @return int number of documents synchronised
     */
    public function syncAll(string $tenantId): int
    {
        $count = 0;

        PurchaseRequisition::query()->where('tenant_id', $tenantId)->each(function (PurchaseRequisition $requisition) use (&$count): void {
            $this->syncRequisition($requisition);
            $count++;
        });

        PurchaseOrder::query()->where('tenant_id', $tenantId)->each(function (PurchaseOrder $order) use (&$count): void {
            $this->syncOrder($order);
            $count++;
        });

        return $count;
    }

    private function syncOrderById(string $orderId): void
    {
        $order = PurchaseOrder::withoutGlobalScopes()->find($orderId);

        if ($order !== null) {
            $this->syncOrder($order);
        }
    }

    /**
     * Value of what was received, invoiced or cancelled per line, in the budget currency: a line is consumed in the
     * proportion of its quantity that is no longer open.
     */
    private function consumedAmount(PurchaseOrder $order, string $netAmount): string
    {
        $lines = PurchaseOrderLine::withoutGlobalScopes()->where('purchase_order_id', $order->id)->get();
        $orderNet = '0';
        $consumedNet = '0';

        foreach ($lines as $line) {
            $ordered = (string) $line->quantity_ordered;
            $lineNet = (string) ($line->line_net_amount ?? '0');
            $orderNet = bcadd($orderNet, $lineNet, 4);

            if (bccomp($ordered, '0', 4) <= 0) {
                continue;
            }

            $done = max((float) $line->quantity_received, (float) $line->quantity_invoiced, (float) $line->quantity_cancelled);
            $share = min(1.0, $done / (float) $ordered);
            $consumedNet = bcadd($consumedNet, bcmul($lineNet, number_format($share, 8, '.', ''), 4), 4);
        }

        if (bccomp($orderNet, '0', 4) <= 0) {
            return '0.00';
        }

        return bcround(bcmul($netAmount, bcdiv($consumedNet, $orderNet, 8), 8), 2);
    }
}
