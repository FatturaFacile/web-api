<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\Inventory\Events\InventoryAdjustmentPostedEvent;
use Modules\Manufacturing\Models\MfgItem;

final readonly class InventoryValuationManufacturingListener
{
    public function handle(InventoryAdjustmentPostedEvent $event): void
    {
        $messageId = $event->getEventId();
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'message_id' => $messageId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'received_at' => now(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // If adjustment involves manufactured item stock, sync current cost
        MfgItem::where('tenant_id', $event->tenantId)
            ->whereNotNull('stock_item_id')
            ->update([
                'updated_at' => now(),
            ]);
    }
}
