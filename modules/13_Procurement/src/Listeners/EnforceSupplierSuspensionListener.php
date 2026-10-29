<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\SupplierSuspendedEvent;
use Modules\Procurement\Models\PurchaseOrder;

final readonly class EnforceSupplierSuspensionListener implements ShouldQueue
{
    public function __construct(
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(SupplierSuspendedEvent $event): void
    {
        $openOrders = PurchaseOrder::where('supplier_id', $event->supplierId)
            ->whereIn('status', ['draft', 'pending_approval'])
            ->get();

        foreach ($openOrders as $order) {
            $beforeState = ['status' => $order->status];
            $order->update([
                'status' => 'cancelled',
                'is_locked' => true,
            ]);

            $this->auditLedger?->record(
                action: 'PURCHASE_ORDER_CANCELLED_DUE_TO_SUPPLIER_SUSPENSION',
                model: $order,
                payloadBefore: $beforeState,
                payloadAfter: ['status' => 'cancelled', 'is_locked' => true, 'reason' => $event->reason],
                moduleCode: 'PROCUREMENT'
            );
        }
    }
}
