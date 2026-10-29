<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\InventoryReceiptRequestedEvent;
use Modules\Procurement\Models\PurchaseReceipt;

final readonly class DispatchInventoryIntegrationOnGoodsReceivedListener implements ShouldQueue
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher
    ) {}

    public function handle(GoodsReceivedEvent $event): void
    {
        $receipt = PurchaseReceipt::find($event->receiptId);
        $warehouseId = $receipt?->warehouse_id ?? (string) Str::uuid();

        $this->eventDispatcher->dispatch(new InventoryReceiptRequestedEvent(
            receiptId: $event->receiptId,
            purchaseOrderId: $event->purchaseOrderId,
            warehouseId: (string) $warehouseId,
            items: $event->receivedLines,
            tenantId: $event->tenantId,
            companyId: $event->companyId
        ));
    }
}
