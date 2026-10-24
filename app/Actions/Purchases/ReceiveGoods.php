<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Services\Inventory\StockService;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiveGoods
{
    public function __construct(
        private readonly DocumentNumberingService $numbering,
        private readonly StockService $stock,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * Receive goods against a purchase order (goods receipt / "bollettino d'entrata"). Supports partial receipts:
     * `lines` maps purchase order item ids to quantities, otherwise every open quantity is received.
     * Tracked products are loaded into stock at the order unit price.
     *
     * @param  array<string, float|int|string>  $lines
     * @param  array{date?: string, notes?: ?string}  $attributes
     */
    public function execute(PurchaseOrder $order, array $lines = [], array $attributes = []): GoodsReceipt
    {
        return DB::transaction(function () use ($order, $lines, $attributes): GoodsReceipt {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);

            if (! in_array($order->status, ['sent', 'partially_received'], true)) {
                throw ValidationException::withMessages(['order' => __('purchases.order_not_receivable')]);
            }

            $toReceive = [];

            foreach ($order->items()->lockForUpdate()->get() as $item) {
                $open = round((float) $item->quantity - (float) $item->quantity_received, 4);
                $requested = $lines === [] ? $open : round((float) ($lines[$item->id] ?? 0), 4);

                if ($requested <= 0) {
                    continue;
                }

                if ($requested > $open) {
                    throw ValidationException::withMessages(['lines' => __('purchases.receipt_exceeds_order', ['description' => $item->description])]);
                }

                if ($item->product_id !== null && ($product = Product::find($item->product_id)) !== null) {
                    $this->stock->assertReceivable($product, 'lines');
                }

                $toReceive[] = [$item, $requested];
            }

            if ($toReceive === []) {
                throw ValidationException::withMessages(['lines' => __('purchases.nothing_to_receive')]);
            }

            $receipt = GoodsReceipt::create([
                'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $order->id,
                'receipt_number' => $this->numbering->next('goods_receipt')['number'],
                'date' => $attributes['date'] ?? now()->toDateString(),
                'notes' => $attributes['notes'] ?? null,
            ]);

            foreach ($toReceive as [$item, $quantity]) {
                $receipt->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_price,
                ]);
                $item->increment('quantity_received', $quantity);
            }

            $this->stock->recordForLines('in', $receipt, $receipt->items()->get());

            $complete = ! $order->items()->whereColumn('quantity_received', '<', 'quantity')->exists();
            $order->update(['status' => $complete ? 'received' : 'partially_received']);
            $this->events->record($order, 'goods_received', ['goods_receipt_id' => $receipt->id]);

            return $receipt->load('items');
        });
    }
}
