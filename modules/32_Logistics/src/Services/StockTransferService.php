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
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\TransferPurpose;
use Modules\Logistics\Enums\TransferStatus;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Events\InterCompanyTransferShipped;
use Modules\Logistics\Events\StockTransferReceived;
use Modules\Logistics\Events\StockTransferShipped;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Logistics\Models\StockTransferOrderLine;
use Modules\Logistics\Models\TransferDiscrepancy;
use Modules\Logistics\Support\LogisticsMessage;

/**
 * Two-step transfers: draft -> approved -> ship (origin -> transit + DDT) -> receive (transit -> destination, possibly
 * in several partial receipts) with the shortage/damage discrepancy workflow, ending in a closed transfer.
 * Stock only leaves the transit location for write-offs / returns after an explicit approval.
 */
final class StockTransferService
{
    public function __construct(
        private readonly WarehouseStockService $stock,
        private readonly DeliveryNoteService $deliveryNotes,
        private readonly DocumentNumberingService $numbering,
        private readonly TenantContext $tenantContext,
        private readonly InterCompanyInvoicingService $interCompanyInvoicing,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_id: string, quantity: float|int|string, description?: string}>  $lines
     */
    public function create(array $data, array $lines): StockTransferOrder
    {
        $purpose = TransferPurpose::from((string) $data['purpose']);
        if ($lines === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_NEEDS_LINE'));
        }
        if ($data['origin_warehouse_id'] === $data['destination_warehouse_id']) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.WAREHOUSES_MUST_DIFFER'));
        }

        $origin = Warehouse::query()->findOrFail($data['origin_warehouse_id']);
        $destination = Warehouse::query()->findOrFail($data['destination_warehouse_id']);
        $this->assertRouteMatchesPurpose($purpose, $origin, $destination);

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
                    throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.LINE_QTY_POSITIVE'));
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

    public function approve(StockTransferOrder $order, ?string $userId = null): StockTransferOrder
    {
        return DB::transaction(function () use ($order, $userId): StockTransferOrder {
            $order = StockTransferOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== TransferStatus::Draft->value) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_APPROVE_DRAFT_ONLY'));
            }

            $order->update([
                'status' => TransferStatus::Approved->value,
                'approved_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
                'approved_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * @param  array<string, mixed>  $deliveryData
     */
    public function ship(StockTransferOrder $order, array $deliveryData = []): StockTransferOrder
    {
        return DB::transaction(function () use ($order, $deliveryData): StockTransferOrder {
            $order = StockTransferOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== TransferStatus::Approved->value) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_SHIP_APPROVED_ONLY'));
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

            $header = array_merge($deliveryData, [
                'type' => $purpose->deliveryNoteType()->value,
                'origin_warehouse_id' => (string) $origin->id,
                'destination_warehouse_id' => (string) $destination->id,
                'stock_transfer_order_id' => (string) $order->id,
                'show_prices' => $purpose->showsPricesOnDdt(),
            ]);
            if ($purpose === TransferPurpose::CustomerConsignment && $destination->linked_partner_id !== null) {
                $header['contact_id'] = (string) $destination->linked_partner_id;
            }
            if ($purpose === TransferPurpose::ConsignmentReturn && $origin->linked_partner_id !== null) {
                $header['contact_id'] = (string) $origin->linked_partner_id;
            }
            if ($purpose === TransferPurpose::Subcontracting && $destination->linked_partner_id !== null) {
                $header['supplier_id'] = (string) $destination->linked_partner_id;
            }
            if ($purpose === TransferPurpose::SubcontractingReturn && $origin->linked_partner_id !== null) {
                $header['supplier_id'] = (string) $origin->linked_partner_id;
            }

            $note = $this->deliveryNotes->create($header, $items);
            $note = $this->deliveryNotes->startTransit($this->deliveryNotes->issue($note));

            $interCompanyInvoiceId = $order->is_inter_company
                ? $this->interCompanyInvoicing->draftInvoice($order, $destination, (string) $note->ddt_number)
                : null;

            $order->update([
                'status' => TransferStatus::InTransit->value,
                'transit_warehouse_id' => $transit->id,
                'delivery_note_id' => $note->id,
                'inter_company_invoice_id' => $interCompanyInvoiceId,
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
     * Registers a receipt. Per line `received_qty` are the good units arriving with this receipt (omitted lines receive
     * everything still outstanding) and `damaged_qty` the units that arrived damaged (they stay on the transit location,
     * quarantined, until a manager resolves the discrepancy).
     *
     * With `$final = false` the outstanding quantity is still on its way: the transfer becomes partially received and no
     * discrepancy is raised. With `$final = true` (default) whatever did not arrive is a shortage.
     *
     * @param  list<array{line_id: string, received_qty?: float|int|string, damaged_qty?: float|int|string, discrepancy_reason?: ?string}>  $received
     */
    public function receive(StockTransferOrder $order, array $received = [], bool $final = true): StockTransferOrder
    {
        return DB::transaction(function () use ($order, $received, $final): StockTransferOrder {
            $order = StockTransferOrder::query()->with('lines')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [TransferStatus::InTransit->value, TransferStatus::PartiallyReceived->value], true)) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_RECEIVE_IN_TRANSIT_ONLY'));
            }

            $byLine = [];
            foreach ($received as $row) {
                $byLine[(string) $row['line_id']] = $row;
            }
            foreach (array_keys($byLine) as $lineId) {
                if (! $order->lines->contains('id', $lineId)) {
                    throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.LINE_NOT_IN_TRANSFER', ['line' => $lineId]));
                }
            }

            $outstanding = [];
            foreach ($order->lines as $line) {
                $row = $byLine[(string) $line->id] ?? null;
                $remaining = $this->remaining($line);
                $good = $row === null ? $remaining : (float) ($row['received_qty'] ?? 0);
                $damaged = $row === null ? 0.0 : (float) ($row['damaged_qty'] ?? 0);
                if ($good < 0.0 || $damaged < 0.0 || round($good + $damaged, 4) > $remaining) {
                    throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.RECEIVED_QTY_RANGE', ['line' => $line->id, 'shipped' => $remaining]));
                }

                if ($good > 0.0) {
                    $this->stock->move((string) $line->product_id, (string) $order->transit_warehouse_id, (string) $order->destination_warehouse_id, $good, 'transfer_receive', StockTransferOrder::class, (string) $order->id);
                }
                $line->update([
                    'received_qty' => round((float) $line->received_qty + $good, 4),
                    'damaged_qty' => round((float) $line->damaged_qty + $damaged, 4),
                    'discrepancy_reason' => $row['discrepancy_reason'] ?? $line->discrepancy_reason,
                ]);
                $outstanding[(string) $line->id] = round($remaining - $good - $damaged, 4);
            }

            $complete = max($outstanding) <= 0.0;
            if (! $final && ! $complete) {
                $order->update(['status' => TransferStatus::PartiallyReceived->value, 'received_at' => now()]);

                return $order->load('lines');
            }

            $hasDiscrepancies = false;
            foreach ($order->lines as $line) {
                $shortage = $outstanding[(string) $line->id];
                $damage = (float) $line->damaged_qty;
                $variance = round($shortage + $damage, 4);
                $line->update([
                    'discrepancy_qty' => $variance,
                    'discrepancy_reason' => $variance > 0.0 ? $line->discrepancy_reason : null,
                ]);
                if ($variance <= 0.0) {
                    continue;
                }
                $hasDiscrepancies = true;
                TransferDiscrepancy::create([
                    'tenant_id' => $order->tenant_id,
                    'stock_transfer_order_id' => $order->id,
                    'stock_transfer_order_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'discrepancy_qty' => $variance,
                    'shortage_qty' => $shortage,
                    'damage_qty' => $damage,
                    'discrepancy_reason' => $line->discrepancy_reason,
                    'status' => DiscrepancyStatus::PendingApproval->value,
                ]);
            }

            $order->update([
                'status' => $hasDiscrepancies ? TransferStatus::DiscrepancyPending->value : TransferStatus::Received->value,
                'received_at' => now(),
            ]);

            if ($order->delivery_note_id !== null) {
                $note = SalesDeliveryNote::query()->find($order->delivery_note_id);
                if ($note !== null && in_array($note->status, ['issued', 'in_transit'], true)) {
                    $this->deliveryNotes->markDelivered($note);
                }
            }

            event(new StockTransferReceived((string) $order->id, (string) $order->tenant_id, $hasDiscrepancies));

            return $order->load(['lines', 'discrepancies']);
        });
    }

    /**
     * Approves a discrepancy; only now the corrective movements are generated. The transfer closes with the last one.
     */
    public function resolveDiscrepancy(TransferDiscrepancy $discrepancy, DiscrepancyStatus $resolution, ?string $userId = null): TransferDiscrepancy
    {
        if (! in_array($resolution->value, DiscrepancyStatus::resolutions(), true)) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.INVALID_RESOLUTION'));
        }

        return DB::transaction(function () use ($discrepancy, $resolution, $userId): TransferDiscrepancy {
            $discrepancy = TransferDiscrepancy::query()->lockForUpdate()->findOrFail($discrepancy->id);
            if ($discrepancy->status !== DiscrepancyStatus::PendingApproval->value) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DISCREPANCY_RESOLVED'));
            }

            $order = StockTransferOrder::query()->findOrFail($discrepancy->stock_transfer_order_id);
            $qty = (float) $discrepancy->discrepancy_qty;
            $productId = (string) $discrepancy->product_id;

            match ($resolution) {
                DiscrepancyStatus::ReturnedToOrigin => $this->stock->move($productId, (string) $order->transit_warehouse_id, (string) $order->origin_warehouse_id, $qty, 'transfer_return', StockTransferOrder::class, (string) $order->id),
                DiscrepancyStatus::ApprovedWriteOff => $this->stock->move($productId, (string) $order->transit_warehouse_id, null, $qty, 'transfer_write_off', StockTransferOrder::class, (string) $order->id, $discrepancy->discrepancy_reason),
                DiscrepancyStatus::CarrierClaim => $this->stock->move($productId, (string) $order->transit_warehouse_id, null, $qty, 'transfer_carrier_claim', StockTransferOrder::class, (string) $order->id, $discrepancy->discrepancy_reason),
                default => throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.INVALID_RESOLUTION')),
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
                $order->update(['status' => TransferStatus::Closed->value, 'closed_at' => now()]);
            }

            return $discrepancy->refresh();
        });
    }

    /** Closes a fully received transfer (the discrepancy path closes automatically once everything is resolved). */
    public function close(StockTransferOrder $order): StockTransferOrder
    {
        return DB::transaction(function () use ($order): StockTransferOrder {
            $order = StockTransferOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== TransferStatus::Received->value) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_CLOSE_STATE'));
            }
            $order->update(['status' => TransferStatus::Closed->value, 'closed_at' => now()]);

            return $order->load('lines');
        });
    }

    public function cancel(StockTransferOrder $order): StockTransferOrder
    {
        if (! in_array($order->status, [TransferStatus::Draft->value, TransferStatus::Approved->value], true)) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.TRANSFER_CANCEL_DRAFT_ONLY'));
        }
        $order->update(['status' => TransferStatus::Cancelled->value]);

        return $order;
    }

    /** Units shipped and not yet accounted for (received good or damaged). */
    private function remaining(StockTransferOrderLine $line): float
    {
        return round((float) $line->shipped_qty - (float) $line->received_qty - (float) $line->damaged_qty, 4);
    }

    private function assertRouteMatchesPurpose(TransferPurpose $purpose, Warehouse $origin, Warehouse $destination): void
    {
        if ($origin->warehouse_type === WarehouseType::Transit->value || $origin->legal_owner_type !== LegalOwnerType::Company->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CANNOT_SHIP_FROM_TRANSIT'));
        }

        $originMustBe = match ($purpose) {
            TransferPurpose::ConsignmentReturn => WarehouseType::CustomerConsignment,
            TransferPurpose::SubcontractingReturn => WarehouseType::Subcontractor,
            default => null,
        };
        if ($originMustBe !== null && $origin->warehouse_type !== $originMustBe->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.ORIGIN_TYPE_FOR_PURPOSE', ['type' => $originMustBe->value]));
        }

        $destinationMustBe = match ($purpose) {
            TransferPurpose::CustomerConsignment => WarehouseType::CustomerConsignment,
            TransferPurpose::Subcontracting => WarehouseType::Subcontractor,
            TransferPurpose::TechnicianVan => WarehouseType::MobileVan,
            default => null,
        };
        if ($destinationMustBe !== null && $destination->warehouse_type !== $destinationMustBe->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_FOR_PURPOSE', ['type' => $destinationMustBe->value]));
        }

        // Returns and inter-company moves land on our own premises.
        $ownPremises = [WarehouseType::Standard->value, WarehouseType::MobileVan->value];
        $needsOwnPremises = in_array($purpose, [TransferPurpose::ConsignmentReturn, TransferPurpose::SubcontractingReturn, TransferPurpose::InterCompany], true);
        if ($needsOwnPremises && ! in_array($destination->warehouse_type, $ownPremises, true)) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_INVALID'));
        }
        if ($purpose === TransferPurpose::InterWarehouse
            && in_array($destination->warehouse_type, [WarehouseType::Transit->value, WarehouseType::VendorConsignment->value], true)) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.DEST_TYPE_INVALID'));
        }

        if ($purpose === TransferPurpose::InterCompany) {
            if ($origin->company_entity_id === null || $destination->company_entity_id === null || $origin->company_entity_id === $destination->company_entity_id) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.INTER_COMPANY_ENTITIES'));
            }
            if ($destination->linked_partner_id === null) {
                throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.INTER_COMPANY_PARTNER'));
            }
        }
    }
}
