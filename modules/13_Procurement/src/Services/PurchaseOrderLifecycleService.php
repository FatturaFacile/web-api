<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierContract;

/**
 * Status changes of a purchase order outside approval and receiving: editing a draft, sending it to the supplier,
 * closing and reopening. Every change is checked against App\Enums\PurchaseOrderStatus.
 */
final readonly class PurchaseOrderLifecycleService
{
    public function __construct(
        private ProcurementService $procurement,
        private ProcurementAuditLedgerService $auditLedger,
    ) {}

    /**
     * Updates the header and, when given, replaces the lines of a draft or rejected order (a rejected order goes back
     * to draft).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ProcurementRuleViolation when the order is not editable
     */
    public function updateDraft(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array((string) $locked->status, [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::Rejected->value], true) || $locked->is_locked) {
                throw new ProcurementRuleViolation("An order in status {$locked->status} cannot be edited.", 'status');
            }

            $header = array_intersect_key($data, array_flip(['expected_delivery_date', 'payment_terms_code', 'incoterms', 'shipping_address', 'notes', 'project_id', 'cost_center_id', 'department_id', 'branch_id', 'currency', 'exchange_rate']));

            if (isset($data['order_date'])) {
                $header['date'] = $data['order_date'];
            }

            $locked->fill([...$header, 'status' => PurchaseOrderStatus::Draft->value])->save();

            if (isset($data['lines']) && is_array($data['lines'])) {
                $supplier = Supplier::query()->findOrFail($locked->supplier_id);
                $contract = $locked->supplier_contract_id !== null ? SupplierContract::query()->whereKey($locked->supplier_contract_id)->lockForUpdate()->first() : null;
                $this->procurement->writeOrderLines($locked, $supplier, $contract, $data['lines']);
            }

            $this->auditLedger->record(action: 'PURCHASE_ORDER_UPDATED', model: $locked, payloadBefore: ['status' => $order->status], payloadAfter: ['status' => $locked->status]);

            return $locked->fresh(['lines', 'supplier']) ?? $locked;
        });
    }

    /**
     * Marks an approved order as sent to the supplier.
     */
    public function markSent(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Sent, ['sent_at' => now()]);
    }

    /**
     * Closes an order: nothing more will be received against it.
     */
    public function close(PurchaseOrder $order): PurchaseOrder
    {
        return $this->transition($order, PurchaseOrderStatus::Closed, ['closed_at' => now()]);
    }

    /**
     * Reopens a closed order: partially received when goods were already received, approved otherwise.
     */
    public function reopen(PurchaseOrder $order): PurchaseOrder
    {
        $received = $order->lines()->where('quantity_received', '>', 0)->exists();

        return $this->transition($order, $received ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Approved, ['closed_at' => null]);
    }

    /**
     * @param  array<string, mixed>  $extra
     *
     * @throws ProcurementRuleViolation when the move is not allowed
     */
    private function transition(PurchaseOrder $order, PurchaseOrderStatus $target, array $extra): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $target, $extra): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;

            if ($from === $target->value || ! PurchaseOrderStatus::allows($from, $target)) {
                throw new ProcurementRuleViolation("An order in status {$from} cannot move to {$target->value}.", 'status');
            }

            $locked->update(['status' => $target->value, ...$extra]);
            $this->auditLedger->record(action: 'PURCHASE_ORDER_'.strtoupper($target->value), model: $locked, payloadBefore: ['status' => $from], payloadAfter: ['status' => $target->value]);

            return $locked->fresh(['lines', 'supplier']) ?? $locked;
        });
    }
}
