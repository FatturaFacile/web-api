<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\DeliveryNote;
use App\Models\SalesOrder;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\DeliveryFulfillmentServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Invoicing\Models\SalesOrder as ModuleSalesOrder;

/**
 * Ships an order from the sales API: the delivery note is created and issued at once by the one delivery note writer
 * (DeliveryFulfillmentService), which checks the open quantities, moves the stock and keeps the backorder open.
 */
class ConvertOrderToDeliveryNote
{
    public function __construct(
        private readonly DeliveryFulfillmentServiceInterface $fulfillment,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Create a delivery note from an order. Supports partial conversion: when `lines` is given
     * (sales_order_item_id => quantity) only those quantities are shipped, otherwise all open quantities.
     *
     * @param  array<string, float|int|string>  $lines
     * @param  array<string, mixed>  $attributes  transport data of the note
     */
    public function execute(SalesOrder $order, array $lines = [], array $attributes = [], ?string $userId = null): DeliveryNote
    {
        return DB::transaction(function () use ($order, $lines, $attributes, $userId): DeliveryNote {
            $items = [];

            foreach ($lines as $itemId => $quantity) {
                $items[] = ['sales_order_item_id' => (string) $itemId, 'quantity' => (string) $quantity];
            }

            $moduleOrder = ModuleSalesOrder::query()->findOrFail($order->id);
            $note = $this->fulfillment->createDeliveryNote($moduleOrder, $attributes + ['created_by' => $userId], $items);
            $this->fulfillment->dispatchDelivery($note, $userId ?? 'system');

            $order->refresh();
            $this->events->record($order, 'delivery_note_created', ['delivery_note_id' => $note->id]);

            return self::appNote($note);
        });
    }

    /**
     * The note as the sales API returns it.
     */
    public static function appNote(SalesDeliveryNote $note): DeliveryNote
    {
        return DeliveryNote::query()->with(['items' => static fn ($query) => $query->orderBy('id')])->findOrFail($note->id);
    }
}
