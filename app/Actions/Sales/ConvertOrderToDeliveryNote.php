<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\DeliveryNote;
use App\Models\DeliveryNoteItem;
use App\Models\SalesOrder;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertOrderToDeliveryNote
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentEventRecorder $events,
        private readonly StockService $stock,
    ) {}

    /**
     * Create a delivery note from an order. Supports partial conversion: when `lines` is given
     * (sales_order_item_id => quantity) only those quantities are shipped, otherwise all open quantities.
     *
     * @param  array<string, float|int|string>  $lines
     * @param  array<string, mixed>  $attributes
     */
    public function execute(SalesOrder $order, array $lines = [], array $attributes = []): DeliveryNote
    {
        if (in_array($order->status, ['cancelled', 'draft'], true)) {
            throw ValidationException::withMessages(['order' => __('sales.order_not_deliverable')]);
        }

        return DB::transaction(function () use ($order, $lines, $attributes): DeliveryNote {
            $toShip = [];
            foreach ($order->items()->orderBy('id')->lockForUpdate()->get() as $item) {
                $deliveredAcrossPriorNotes = (float) DeliveryNoteItem::where('sales_order_item_id', $item->id)
                    ->whereHas('deliveryNote', fn ($q) => $q->whereNull('deleted_at'))
                    ->sum('quantity');

                $delivered = max((float) $item->quantity_delivered, $deliveredAcrossPriorNotes);
                $open = round(max(0.0, (float) $item->quantity - $delivered), 4);
                $requested = $lines === [] ? $open : round((float) ($lines[$item->id] ?? 0), 4);

                if ($requested <= 0) {
                    continue;
                }

                if ($requested > $open) {
                    throw ValidationException::withMessages(['lines' => "Quantity for '{$item->description}' exceeds the open quantity."]);
                }

                $toShip[] = [$item, $requested, $delivered];
            }

            if ($toShip === []) {
                throw ValidationException::withMessages(['lines' => __('sales.nothing_to_deliver')]);
            }

            $number = $this->numbering->next('delivery_note');

            $note = DeliveryNote::create(array_merge($attributes, [
                'tenant_id' => $order->tenant_id,
                'contact_id' => $order->contact_id,
                'sales_order_id' => $order->id,
                'ddt_number' => $number['number'],
                'date' => now()->toDateString(),
                'status' => 'issued',
            ]));

            foreach ($toShip as [$item, $quantity, $delivered]) {
                $note->items()->create([
                    'sales_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                ]);
                $item->update(['quantity_delivered' => $delivered + $quantity]);
            }

            $this->stock->recordForLines('out', $note, $note->items()->get());

            $fullyDelivered = ! $order->items()->whereColumn('quantity_delivered', '<', 'quantity')->exists();
            $order->update(['status' => $fullyDelivered ? 'shipped' : 'confirmed']);
            $this->events->record($order, 'delivery_note_created', ['delivery_note_id' => $note->id]);

            return $note->load(['items' => fn ($query) => $query->orderBy('id')]);
        });
    }
}
