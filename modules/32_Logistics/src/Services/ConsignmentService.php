<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\TransferOrder;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\OwnershipType;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Events\ConsignmentConsumptionPosted;
use Modules\Logistics\Models\ConsignmentAgreement;
use Modules\Logistics\Models\ConsignmentConsumptionDeclaration;
use Modules\Logistics\Models\ConsignmentConsumptionDeclarationLine;
use Modules\Logistics\Queries\InventoryValuationReportQuery;
use Modules\Logistics\Support\LogisticsMessage;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Models\Supplier;

/**
 * Register of goods held by third parties (customer consignment, subcontractors) and goods of third parties held by us
 * (vendor consignment), periodic consumption declarations and the balance-sheet valuation split.
 */
final class ConsignmentService
{
    public function __construct(
        private readonly WarehouseStockService $stock,
        private readonly InvoicingServiceInterface $invoicing,
        private readonly TenantContext $tenantContext,
        private readonly InventoryValuationReportQuery $valuation,
    ) {}

    /**
     * Register of goods at third parties: opening, sent (with DDT refs), consumed, returned, residual and fiscal value.
     *
     * @return list<array<string, mixed>>
     */
    public function ledger(?string $warehouseId = null, ?string $partnerId = null, ?string $from = null, ?string $to = null): array
    {
        $warehouses = Warehouse::query()
            ->whereIn('warehouse_type', [WarehouseType::CustomerConsignment->value, WarehouseType::VendorConsignment->value, WarehouseType::Subcontractor->value])
            ->when($warehouseId !== null, fn ($q) => $q->whereKey($warehouseId))
            ->when($partnerId !== null, fn ($q) => $q->where('linked_partner_id', $partnerId))
            ->orderBy('code')
            ->get();

        $result = [];
        foreach ($warehouses as $warehouse) {
            $entries = $this->entries($warehouse);
            $items = StockItem::query()->whereIn('id', $entries->pluck('stock_item_id')->unique()->values())->pluck('product_id', 'id');
            $productIds = $items->filter()->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            $rows = [];
            foreach ($productIds as $productId) {
                $own = $entries->filter(fn (array $e): bool => ($items[$e['stock_item_id']] ?? null) === $productId);
                $inPeriod = $own->filter(fn (array $e): bool => ($from === null || $e['at']->gte(Carbon::parse($from))) && ($to === null || $e['at']->lte(Carbon::parse($to)->endOfDay())));
                $before = $from === null ? collect() : $own->filter(fn (array $e): bool => $e['at']->lt(Carbon::parse($from)));

                $opening = $before->sum(fn (array $e): float => $e['signed']);
                $sent = $inPeriod->where('inbound', true)->sum(fn (array $e): float => $e['quantity']);
                $consumed = $inPeriod->where('type', 'consignment_consumption_out')->sum(fn (array $e): float => $e['quantity']);
                $returned = $inPeriod->where('inbound', false)->where('type', '!=', 'consignment_consumption_out')->sum(fn (array $e): float => $e['quantity']);
                $residual = $this->stock->onHand((string) $warehouse->id, (string) $productId);
                $unitCost = (float) ($products[$productId]->cost_price ?? 0);

                $rows[] = [
                    'product_id' => (string) $productId,
                    'sku' => $products[$productId]->sku ?? null,
                    'name' => $products[$productId]->name ?? null,
                    'opening_qty' => round($opening, 4),
                    'sent_qty' => round($sent, 4),
                    'consumed_qty' => round($consumed, 4),
                    'returned_qty' => round($returned, 4),
                    'residual_qty' => round($residual, 4),
                    'unit_cost' => $unitCost,
                    'fiscal_value' => round($residual * $unitCost, 2),
                    'ddt_references' => $this->ddtReferences($inPeriod->where('inbound', true)->filter(fn (array $e): bool => $e['document_id'] !== null)),
                ];
            }

            $result[] = [
                'warehouse_id' => (string) $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'warehouse_name' => $warehouse->name,
                'warehouse_type' => $warehouse->warehouse_type,
                'legal_owner_type' => $warehouse->legal_owner_type,
                'partner_id' => $warehouse->linked_partner_id,
                'on_balance_sheet' => $warehouse->legal_owner_type === LegalOwnerType::Company->value,
                'total_fiscal_value' => round(array_sum(array_column($rows, 'fiscal_value')), 2),
                'rows' => $rows,
            ];
        }

        return $result;
    }

    /**
     * The movements that changed the physical stock of a warehouse: into one of its bins (inbound) or out of one of its
     * bins (outbound). Moves inside the warehouse and the transit leg of a transfer do not change what it holds.
     *
     * @return Collection<int, array{stock_item_id: string, quantity: float, signed: float, inbound: bool, type: string, at: Carbon, document_type: ?string, document_id: ?string}>
     */
    private function entries(Warehouse $warehouse): Collection
    {
        $binIds = DB::table('warehouse_bins as b')
            ->join('warehouse_racks as r', 'r.id', '=', 'b.warehouse_rack_id')
            ->join('warehouse_aisles as a', 'a.id', '=', 'r.warehouse_aisle_id')
            ->join('warehouse_zones as z', 'z.id', '=', 'a.warehouse_zone_id')
            ->where('z.warehouse_id', $warehouse->id)
            ->where('z.zone_type', '!=', 'transit')
            ->pluck('b.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $bins = array_flip($binIds);

        return StockMovement::query()
            ->where(fn ($q) => $q->whereIn('source_location_id', $binIds)->orWhereIn('destination_location_id', $binIds))
            ->whereNotNull('stock_item_id')
            ->orderBy('executed_at')
            ->get()
            ->map(function (StockMovement $m) use ($bins): ?array {
                $in = isset($bins[(string) $m->destination_location_id]);
                $out = isset($bins[(string) $m->source_location_id]);
                if ($in === $out) {
                    return null;
                }
                $qty = (float) $m->quantity;

                return [
                    'stock_item_id' => (string) $m->stock_item_id,
                    'quantity' => $qty,
                    'signed' => $in ? $qty : -$qty,
                    'inbound' => $in,
                    'type' => (string) $m->movement_type,
                    'at' => Carbon::parse($m->executed_at),
                    'document_type' => $m->origin_document_type,
                    'document_id' => $m->origin_document_id !== null ? (string) $m->origin_document_id : null,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Declares consumption and posts it: stock leaves the consignment warehouse for good and a draft sales invoice
     * (customer consignment) or a draft purchase order (vendor consignment) is generated.
     *
     * @param  array{warehouse_id: string, agreement_id?: ?string, period_start: string, period_end: string, notes?: ?string}  $data
     * @param  list<array{product_id: string, quantity: float|int|string, unit_price?: float|int|string|null, tax_rate?: float|int|string|null}>  $lines
     */
    public function declareConsumption(array $data, array $lines): ConsignmentConsumptionDeclaration
    {
        if ($lines === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_CONSUMED_PRODUCT'));
        }

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        if (! in_array($warehouse->warehouse_type, [WarehouseType::CustomerConsignment->value, WarehouseType::VendorConsignment->value], true)) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.CONSUMPTION_WAREHOUSE_TYPE'));
        }
        $direction = $this->directionFor($warehouse);
        if ($warehouse->linked_partner_id === null) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_PARTNER_TO_BILL'));
        }
        $agreement = $this->resolveAgreement($warehouse, $data['agreement_id'] ?? null);

        return DB::transaction(function () use ($data, $lines, $warehouse, $direction, $agreement): ConsignmentConsumptionDeclaration {
            $declaration = ConsignmentConsumptionDeclaration::create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'warehouse_id' => $warehouse->id,
                'partner_id' => $warehouse->linked_partner_id,
                'direction' => $direction,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => 'draft',
                'agreement_id' => $agreement?->id,
                // Ownership shift booked with the declaration: vendor goods become ours, our goods at the customer become theirs.
                'ownership_from' => $direction === 'vendor' ? OwnershipType::VendorConsignment->value : OwnershipType::CustomerConsignment->value,
                'ownership_to' => $direction === 'vendor' ? OwnershipType::Owned->value : 'transferred_to_customer',
                'notes' => $data['notes'] ?? null,
            ]);

            $documentLines = [];
            foreach ($lines as $line) {
                $qty = (float) $line['quantity'];
                $product = Product::query()->findOrFail($line['product_id']);
                $defaultPrice = (float) ($direction === 'customer' ? $product->sale_price : $product->cost_price);
                $price = isset($line['unit_price']) ? (float) $line['unit_price'] : $defaultPrice;
                $tax = isset($line['tax_rate']) ? (float) $line['tax_rate'] : (float) $product->tax_rate;

                $this->stock->move((string) $product->id, (string) $warehouse->id, null, $qty, 'consignment_consumption', ConsignmentConsumptionDeclaration::class, (string) $declaration->id, unitCost: (float) $product->cost_price);

                ConsignmentConsumptionDeclarationLine::create([
                    'tenant_id' => $declaration->tenant_id,
                    'declaration_id' => $declaration->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $tax,
                ]);
                $documentLines[] = ['product' => $product, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $tax];
            }

            [$type, $documentId, $total] = $direction === 'customer'
                ? $this->draftSalesInvoice($declaration, $documentLines)
                : $this->draftPurchaseOrder($declaration, $documentLines);

            $declaration->update([
                'status' => 'posted',
                'generated_document_type' => $type,
                'generated_document_id' => $documentId,
                'total_amount' => $total,
            ]);

            event(new ConsignmentConsumptionPosted((string) $declaration->id, $direction, $documentId, (string) $declaration->tenant_id));

            return $declaration->load('lines');
        });
    }

    /**
     * Inventory value for the balance sheet, segregated by legal ownership / location (see InventoryValuationReportQuery).
     *
     * @return array{total_balance_sheet_value: float, excluded_third_party_value: float, buckets: array<string, float>, by_warehouse_type: array<string, float>, by_warehouse: list<array<string, mixed>>}
     */
    public function inventoryValuation(): array
    {
        return $this->valuation->execute();
    }

    /**
     * Books vendor-owned goods received on a vendor consignment warehouse. They stay legally owned by the vendor (excluded
     * from the balance-sheet valuation) until withdrawn by a consumption declaration.
     *
     * @param  list<array{product_id: string, quantity: float|int|string}>  $lines
     */
    public function receiveVendorStock(string $warehouseId, array $lines): Warehouse
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        if ($warehouse->warehouse_type !== WarehouseType::VendorConsignment->value) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.VENDOR_RECEIPT_WAREHOUSE_TYPE'));
        }
        if ($lines === []) {
            throw new InvalidArgumentException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_CONSUMED_PRODUCT'));
        }

        DB::transaction(function () use ($warehouse, $lines): void {
            foreach ($lines as $line) {
                $product = Product::query()->findOrFail($line['product_id']);
                $this->stock->move((string) $product->id, null, (string) $warehouse->id, (float) $line['quantity'], 'vendor_consignment_receipt', unitCost: (float) $product->cost_price);
            }
        });

        return $warehouse;
    }

    /**
     * @param  array{warehouse_id: string, partner_id?: ?string, agreement_number?: ?string, valid_from: string, valid_to?: ?string, billing_frequency?: ?string, payment_terms_days?: ?int, notes?: ?string}  $data
     */
    public function createAgreement(array $data): ConsignmentAgreement
    {
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $direction = $this->directionFor($warehouse);
        $partnerId = $data['partner_id'] ?? $warehouse->linked_partner_id;
        if ($partnerId === null) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_PARTNER_TO_BILL'));
        }

        return ConsignmentAgreement::create([
            'tenant_id' => $this->tenantContext->getTenantId(),
            'agreement_number' => $data['agreement_number'] ?? 'CA-'.now()->format('Y').'-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid7()), -6)),
            'direction' => $direction,
            'partner_id' => $partnerId,
            'warehouse_id' => $warehouse->id,
            'status' => 'active',
            'billing_frequency' => $data['billing_frequency'] ?? 'monthly',
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null,
            'payment_terms_days' => $data['payment_terms_days'] ?? 30,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    public function terminateAgreement(ConsignmentAgreement $agreement): ConsignmentAgreement
    {
        $agreement->update(['status' => 'terminated', 'valid_to' => $agreement->valid_to ?? now()->toDateString()]);

        return $agreement;
    }

    private function directionFor(Warehouse $warehouse): string
    {
        return match ($warehouse->warehouse_type) {
            WarehouseType::CustomerConsignment->value => 'customer',
            WarehouseType::VendorConsignment->value => 'vendor',
            default => throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.AGREEMENT_WAREHOUSE_TYPE')),
        };
    }

    private function resolveAgreement(Warehouse $warehouse, ?string $agreementId): ?ConsignmentAgreement
    {
        $query = ConsignmentAgreement::query()->where('warehouse_id', $warehouse->id);
        if ($agreementId === null) {
            return $query->where('status', 'active')->orderByDesc('valid_from')->first();
        }
        $agreement = $query->findOrFail($agreementId);
        if ($agreement->status !== 'active') {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.AGREEMENT_NOT_ACTIVE'));
        }

        return $agreement;
    }

    /**
     * @param  list<array{product: Product, quantity: float, unit_price: float, tax_rate: float}>  $documentLines
     * @return array{0: string, 1: string, 2: float}
     */
    private function draftSalesInvoice(ConsignmentConsumptionDeclaration $declaration, array $documentLines): array
    {
        $items = array_map(fn (array $l): array => [
            'product_id' => (string) $l['product']->id,
            'description' => sprintf('%s (consumo conto deposito %s - %s)', $l['product']->name, $declaration->period_start->format('d/m/Y'), $declaration->period_end->format('d/m/Y')),
            'quantity' => $l['quantity'],
            'unit_price' => $l['unit_price'],
            'tax_rate' => $l['tax_rate'],
        ], $documentLines);

        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => (string) $declaration->tenant_id,
            'contact_id' => (string) $declaration->partner_id,
            'notes' => 'Fattura a consumo conto deposito',
            'created_by' => auth()->id(),
        ], $items);

        return ['sales_invoice', (string) $invoice->id, (float) $invoice->total_gross];
    }

    /**
     * @param  list<array{product: Product, quantity: float, unit_price: float, tax_rate: float}>  $documentLines
     * @return array{0: string, 1: string, 2: float}
     */
    /** The procurement supplier of a vendor consignment partner: the supplier record linked to the contact (or the supplier itself). */
    private function supplierIdOf(string $partnerId): string
    {
        $supplier = Supplier::query()->where('contact_id', $partnerId)->orWhere('id', $partnerId)->first();
        if ($supplier === null) {
            throw new DomainException(LogisticsMessage::get('LOGISTICS.ERRORS.NO_PARTNER_TO_BILL'));
        }

        return (string) $supplier->id;
    }

    private function draftPurchaseOrder(ConsignmentConsumptionDeclaration $declaration, array $documentLines): array
    {
        $order = app(ProcurementServiceInterface::class)->createPurchaseOrder(new CreatePurchaseOrderDTO(
            supplierId: $this->supplierIdOf((string) $declaration->partner_id),
            orderDate: now()->toDateString(),
            expectedDeliveryDate: now()->toDateString(),
            lines: array_map(fn (array $l): array => [
                'description' => sprintf('%s (prelievo conto deposito fornitore)', $l['product']->name),
                'quantity_ordered' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'uom' => (string) ($l['product']->unit_measure ?? 'PZ'),
                'tax_rate' => $l['tax_rate'],
            ], $documentLines),
            notes: 'Ordine generato da prelievo conto deposito fornitore',
            tenantId: (string) $declaration->tenant_id,
            creatorUserId: auth()->id() !== null ? (string) auth()->id() : null,
        ));

        return ['purchase_order', (string) $order->id, (float) $order->total_gross];
    }

    /**
     * @param  Collection<int, array{document_type: ?string, document_id: ?string}>  $entries
     * @return list<string>
     */
    private function ddtReferences(Collection $entries): array
    {
        $numbers = [];
        foreach ($entries as $entry) {
            if ($entry['document_type'] === SalesDeliveryNote::class) {
                $number = SalesDeliveryNote::query()->whereKey($entry['document_id'])->value('ddt_number');
            } elseif ($entry['document_type'] === TransferOrder::class) {
                $noteId = TransferOrder::query()->whereKey($entry['document_id'])->value('delivery_note_id');
                $number = $noteId !== null ? SalesDeliveryNote::query()->whereKey($noteId)->value('ddt_number') : null;
            } else {
                $number = null;
            }
            if ($number !== null) {
                $numbers[(string) $number] = true;
            }
        }

        return array_keys($numbers);
    }
}
