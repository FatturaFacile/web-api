<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use App\Services\Sales\DocumentNumberingService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\DiscrepancyStatus;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Enums\TransferStatus;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Events\InterCompanyTransferShipped;
use Modules\Logistics\Events\StockTransferReceived;
use Modules\Logistics\Events\StockTransferShipped;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Models\StockTransferOrderLine;
use Modules\Logistics\Models\TransferDiscrepancy;

/**
 * Two-step transfers: ship (origin -> transit + DDT) then receive (transit -> destination) with discrepancy workflow.
 * Stock only leaves the transit location for write-offs / returns after an explicit approval.
 */
final class StockTransferService
{
    public function __construct(
        private readonly WarehouseStockService $stock,
        private readonly DeliveryNoteService $deliveryNotes,
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: string, quantity: float|int|string, description?: string}>  $lines
     */
    public function create(array $data, array $lines): StockTransferOrder
    {
        $purpose = TransferPurpose::from((string) $data['purpose']);
        if ($lines === []) {
            throw new InvalidArgumentException('A transfer needs at least one line.');
        }
        if ($data['origin_warehouse_id'] === $data['destination_warehouse_id']) {
            throw new InvalidArgumentException('Origin and destination warehouses must differ.');
        }

        $origin = Warehouse::query()->findOrFail($data['origin_warehouse_id']);
        $destination = Warehouse::query()->findOrFail($data['destination_warehouse_id']);
        $this->assertDestinationMatchesPurpose($purpose, $destination);
        if ($origin->warehouse_type === WarehouseType::Transit->value || $origin->legal_owner_type !== 'company') {
            throw new DomainException('Stock cannot be shipped from a transit location or from third-party-owned stock.');
        }

        return DB::transaction(function () use ($data, $lines, $purpose, $origin, $destination): StockTransferOrder {
            $number = $this->numbering->next('stock_transfer', null, null, 5);
            $interCompany = $origin->company_entity_id !== null && $destination->company_entity_id !== null
                && $origin->company_entity_id !== $destination->company_entity_id;

            $order = StockTransferOrder::create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'transfer_number' => $number['number'],
                'purpose' => $purpose->value,
                'status' => TransferStatus::Draft->value,
                'origin_warehouse_id' => $origin->id,
                'transit_warehouse_id' => $data['transit_warehouse_id'] ?? null,
                'destination_warehouse_id' => $destination->id,
                'is_inter_company' => $interCompany,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $qty = (float) $line['quantity'];
                if ($qty <= 0.0) {
                    throw new InvalidArgumentException('Line quantity must be greater than zero.');
                }
                $product = Product::query()->findOrFail($line['product_id']);
                StockTransferOrderLine::create([
                    'tenant_id' => $order->tenant_id,
                    'stock_transfer_order_id' => $order->id,
                    'product_id' => $product->id,
                    'description' => $line['description'] ?? $product->name,
                    'quantity' => $qty,
                ]);
            }

            return $order->load('lines');
        });
    }

    public function ship(StockTransferOrder $order, array $deliveryData = []): StockTransferOrder
    {
        return DB::transaction(function () use ($order, $deliveryData): StockTransferOrder {
            $order = StockTransferOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== TransferStatus::Draft->value) {
                throw new DomainException('Only draft transfers can be shipped.');
            }

            $purpose = TransferPurpose::from((string) $order->purpose);
            $origin = Warehouse::query()->findOrFail($order->origin_warehouse_id);
            $destination = Warehouse::query()->findOrFail($order->destination_warehouse_id);
            $transit = $this->stock->resolveTransitWarehouse($order->transit_warehouse_id);

            $items = [];
            foreach ($order->lines as $line) {
                $product = Product::query()->findOrFail($line->product_id);
                $this->stock->move(
                    (string) $line->product_id,
                    (string) $origin->id,
                    (string) $transit->id,
                    (float) $line->quantity,
                    'transfer_ship',
                    StockTransferOrder::class,
                    (string) $order->id,
                    unitCost: (float) $product->cost_price,
                );
                $line->update(['shipped_qty' => $line->quantity]);

                $items[] = [
                    'product_id' => (string) $product->id,
                    'description' => (string) $line->description,
                    'quantity' => (float) $line->quantity,
                    // Fiscal value of the goods travelling (at cost): required on consignment / subcontracting DDTs.
                    'unit_price' => (float) $product->cost_price,
                    'tax_rate' => 0.0,
                    'uom' => $product->unit_measure,
                ];
            }

            $type = $purpose->deliveryNoteType();
            $header = array_merge($deliveryData, [
                'type' => $type->value,
                'origin_warehouse_id' => (string) $origin->id,
                'destination_warehouse_id' => (string) $destination->id,
                'stock_transfer_order_id' => (string) $order->id,
                'show_prices' => $purpose !== TransferPurpose::InterWarehouse && $purpose !== TransferPurpose::TechnicianVan,
            ]);
            if ($purpose === TransferPurpose::CustomerConsignment && $destination->linked_partner_id !== null) {
                $header['contact_id'] = (string) $destination->linked_partner_id;
            }
            if ($purpose === TransferPurpose::Subcontracting && $destination->linked_partner_id !== null) {
                $header['supplier_id'] = (string) $destination->linked_partner_id;
            }

            $note = $this->deliveryNotes->create($header, $items);
            $note = $this->deliveryNotes->issue($note);

            $order->update([
                'status' => TransferStatus::InTransit->value,
                'transit_warehouse_id' => $transit->id,
                'delivery_note_id' => $note->id,
                'shipped_at' => now(),
            ]);

            event(new StockTransferShipped((string) $order->id, (string) $note->id, (string) $order->tenant_id));
            if ($order->is_inter_company) {
                event(new InterCompanyTransferShipped(
                    (string) $order->id,
                    (string) $note->id,
                    (string) $origin->company_entity_id,
                    (string) $destination->company_entity_id,
                    (string) $order->tenant_id,
                ));
            }

            return $order->load('lines');
        });
    }

    /**
     * @param  list<array{line_id: string, received_qty: float|int|string, discrepancy_reason?: ?string}>  $received  lines omitted are received in full
     */
    public function receive(StockTransferOrder $order, array $received = []): StockTransferOrder
    {
        return DB::transaction(function () use ($order, $received): StockTransferOrder {
            $order = StockTransferOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== TransferStatus::InTransit->value) {
                throw new DomainException('Only transfers in transit can be received.');
            }

            $byLine = [];
            foreach ($received as $row) {
                $byLine[(string) $row['line_id']] = $row;
            }
            foreach (array_keys($byLine) as $lineId) {
                if (! $order->lines->contains('id', $lineId)) {
                    throw new InvalidArgumentException("Line {$lineId} does not belong to this transfer.");
                }
            }

            $hasDiscrepancies = false;
            foreach ($order->lines as $line) {
                $row = $byLine[(string) $line->id] ?? null;
                $shipped = (float) $line->shipped_qty;
                $qty = $row !== null ? (float) $row['received_qty'] : $shipped;
                if ($qty < 0.0 || $qty > $shipped) {
                    throw new DomainException("Received quantity for line {$line->id} must be between 0 and the shipped quantity ({$shipped}).");
                }

                if ($qty > 0.0) {
                    $this->stock->move((string) $line->product_id, (string) $order->transit_warehouse_id, (string) $order->destination_warehouse_id, $qty, 'transfer_receive', StockTransferOrder::class, (string) $order->id);
                }

                $missing = round($shipped - $qty, 4);
                $reason = $row['discrepancy_reason'] ?? null;
                $line->update(['received_qty' => $qty, 'discrepancy_qty' => $missing, 'discrepancy_reason' => $missing > 0 ? $reason : null]);

                if ($missing > 0.0) {
                    $hasDiscrepancies = true;
                    TransferDiscrepancy::create([
                        'tenant_id' => $order->tenant_id,
                        'stock_transfer_order_id' => $order->id,
                        'stock_transfer_order_line_id' => $line->id,
                        'product_id' => $line->product_id,
                        'discrepancy_qty' => $missing,
                        'discrepancy_reason' => $reason,
                        'status' => DiscrepancyStatus::PendingApproval->value,
                    ]);
                }
            }

            $order->update([
                'status' => $hasDiscrepancies ? TransferStatus::DiscrepancyPending->value : TransferStatus::Received->value,
                'received_at' => now(),
            ]);

            if ($order->delivery_note_id !== null) {
                $note = SalesDeliveryNote::query()->find($order->delivery_note_id);
                if ($note !== null && $note->status === 'issued') {
                    $this->deliveryNotes->markDelivered($note);
                }
            }

            event(new StockTransferReceived((string) $order->id, (string) $order->tenant_id, $hasDiscrepancies));

            return $order->load(['lines', 'discrepancies']);
        });
    }

    /**
     * Approves a discrepancy; only now the corrective movements are generated.
     */
    public function resolveDiscrepancy(TransferDiscrepancy $discrepancy, DiscrepancyStatus $resolution, ?string $userId = null): TransferDiscrepancy
    {
        if (! in_array($resolution->value, DiscrepancyStatus::resolutions(), true)) {
            throw new InvalidArgumentException('Invalid discrepancy resolution.');
        }

        return DB::transaction(function () use ($discrepancy, $resolution, $userId): TransferDiscrepancy {
            $discrepancy = TransferDiscrepancy::query()->lockForUpdate()->findOrFail($discrepancy->id);
            if ($discrepancy->status !== DiscrepancyStatus::PendingApproval->value) {
                throw new DomainException('This discrepancy has already been resolved.');
            }

            $order = StockTransferOrder::query()->findOrFail($discrepancy->stock_transfer_order_id);
            $qty = (float) $discrepancy->discrepancy_qty;
            $productId = (string) $discrepancy->product_id;

            match ($resolution) {
                DiscrepancyStatus::ReturnedToOrigin => $this->stock->move($productId, (string) $order->transit_warehouse_id, (string) $order->origin_warehouse_id, $qty, 'transfer_return', StockTransferOrder::class, (string) $order->id),
                DiscrepancyStatus::ApprovedWriteOff => $this->stock->move($productId, (string) $order->transit_warehouse_id, null, $qty, 'transfer_write_off', StockTransferOrder::class, (string) $order->id, $discrepancy->discrepancy_reason),
                DiscrepancyStatus::CarrierClaim => $this->stock->move($productId, (string) $order->transit_warehouse_id, null, $qty, 'transfer_carrier_claim', StockTransferOrder::class, (string) $order->id, $discrepancy->discrepancy_reason),
                default => throw new InvalidArgumentException('Invalid discrepancy resolution.'),
            };

            $discrepancy->update([
                'status' => $resolution->value,
                'resolved_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
                'resolved_at' => now(),
            ]);

            $stillPending = TransferDiscrepancy::query()
                ->where('stock_transfer_order_id', $order->id)
                ->where('status', DiscrepancyStatus::PendingApproval->value)
                ->exists();
            if (! $stillPending) {
                $order->update(['status' => TransferStatus::Reconciled->value]);
            }

            return $discrepancy->refresh();
        });
    }

    public function cancel(StockTransferOrder $order): StockTransferOrder
    {
        if ($order->status !== TransferStatus::Draft->value) {
            throw new DomainException('Only draft transfers can be cancelled.');
        }
        $order->update(['status' => TransferStatus::Cancelled->value]);

        return $order;
    }

    private function assertDestinationMatchesPurpose(TransferPurpose $purpose, Warehouse $destination): void
    {
        $expected = match ($purpose) {
            TransferPurpose::CustomerConsignment => WarehouseType::CustomerConsignment,
            TransferPurpose::Subcontracting => WarehouseType::Subcontractor,
            TransferPurpose::TechnicianVan => WarehouseType::MobileVan,
            TransferPurpose::InterWarehouse => null,
        };

        if ($expected !== null && $destination->warehouse_type !== $expected->value) {
            throw new DomainException("The destination warehouse must be of type {$expected->value} for this transfer purpose.");
        }
        if ($expected === null && in_array($destination->warehouse_type, [WarehouseType::Transit->value, WarehouseType::VendorConsignment->value], true)) {
            throw new DomainException('This destination warehouse type is not valid for an inter-warehouse transfer.');
        }
    }
}
