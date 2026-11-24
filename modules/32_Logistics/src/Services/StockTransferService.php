<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use InvalidArgumentException;
use Modules\Inventory\Enums\DiscrepancyStatus;
use Modules\Inventory\Models\TransferDiscrepancy;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\ProductStockItemResolver;
use Modules\Inventory\Services\TransferOrderService;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Logistics view of the transfer orders of the inventory module (the one transfer aggregate): lines are catalog
 * products, the purpose decides the route rules (TransferRoutePolicy) and the fiscal paperwork (TransferDocumentService,
 * reacting to the shipped / received events of the inventory service).
 *
 * Lifecycle: draft -> approved -> in_transit -> partially_received | received | discrepancy_pending -> closed | cancelled.
 */
final class StockTransferService
{
    public function __construct(
        private readonly TransferOrderService $transfers,
        private readonly ProductStockItemResolver $stockItems,
    ) {}

    /**
     * @param  array<string, mixed>  $data  purpose, origin_warehouse_id, destination_warehouse_id, notes?
     * @param  list<array{product_id: string, quantity: float|int|string}>  $lines
     */
    public function create(array $data, array $lines): TransferOrder
    {
        $purpose = TransferPurpose::from((string) $data['purpose']);
        if ($lines === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_NEEDS_LINE'));
        }

        $itemLines = [];
        foreach ($lines as $line) {
            if ((float) $line['quantity'] <= 0.0) {
                throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.LINE_QTY_POSITIVE'));
            }
            $itemLines[] = [
                'stock_item_id' => (string) $this->stockItems->resolve(Product::query()->findOrFail($line['product_id']))->id,
                'quantity' => $line['quantity'],
            ];
        }

        if ($data['origin_warehouse_id'] === $data['destination_warehouse_id']) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.WAREHOUSES_MUST_DIFFER'));
        }

        return $this->transfers->create(
            Warehouse::query()->findOrFail($data['origin_warehouse_id']),
            Warehouse::query()->findOrFail($data['destination_warehouse_id']),
            $itemLines,
            $this->actor(),
            null,
            $data['notes'] ?? null,
            $purpose->value,
        );
    }

    public function approve(TransferOrder $order, ?string $userId = null): TransferOrder
    {
        return $this->transfers->approve($order, $userId ?? $this->actor());
    }

    /**
     * Step 1: origin -> in transit, immutable ledger entries, DDT and (inter-company) invoice draft.
     *
     * @param  array<string, mixed>  $deliveryData
     */
    public function ship(TransferOrder $order, array $deliveryData = []): TransferOrder
    {
        return $this->transfers->ship($order, $this->actor(), $deliveryData);
    }

    /**
     * Step 2: in transit -> destination with variance detection (see TransferOrderService::receive).
     *
     * @param  list<array{line_id: string, received_qty?: float|int|string, damaged_qty?: float|int|string, discrepancy_reason?: ?string}>  $received  lines omitted are received in full
     */
    public function receive(TransferOrder $order, array $received = [], bool $final = true): TransferOrder
    {
        return $this->transfers->receive($order, $received, $this->actor(), $final);
    }

    public function resolveDiscrepancy(TransferDiscrepancy $discrepancy, DiscrepancyStatus $resolution, ?string $userId = null): TransferDiscrepancy
    {
        return $this->transfers->resolveDiscrepancy($discrepancy, $resolution, $userId ?? $this->actor());
    }

    public function close(TransferOrder $order): TransferOrder
    {
        return $this->transfers->close($order);
    }

    public function cancel(TransferOrder $order): TransferOrder
    {
        return $this->transfers->cancel($order);
    }

    private function actor(): ?string
    {
        return auth()->id() !== null ? (string) auth()->id() : null;
    }
}
