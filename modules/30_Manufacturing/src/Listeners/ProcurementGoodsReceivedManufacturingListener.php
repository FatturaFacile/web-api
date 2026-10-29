<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Procurement\Events\GoodsReceivedEvent;

final readonly class ProcurementGoodsReceivedManufacturingListener
{
    public function handle(GoodsReceivedEvent $event): void
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

        // Reconcile open MRP shortages for received stock items
        $lines = $event->receivedLines ?? [];
        foreach ($lines as $line) {
            $stockItemId = $line['stock_item_id'] ?? null;
            if ($stockItemId) {
                $mfgItem = MfgItem::where('tenant_id', $event->tenantId)
                    ->where('stock_item_id', $stockItemId)
                    ->first();

                if ($mfgItem) {
                    $receivedQty = (float) ($line['quantity_received'] ?? 0.0);
                    $shortage = MfgMrpShortage::where('tenant_id', $event->tenantId)
                        ->where('mfg_item_id', $mfgItem->id)
                        ->orderBy('required_date')
                        ->first();

                    if ($shortage) {
                        $newShortage = max(0.0, (float) $shortage->net_shortage - $receivedQty);
                        $shortage->update(['net_shortage' => $newShortage]);
                    }
                }
            }
        }
    }
}
