<?php

declare(strict_types=1);

namespace Modules\Logistics\Services;

use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesDeliveryNote;
use Modules\Logistics\Enums\LegalOwnerType;
use Modules\Logistics\Enums\WarehouseType;
use Modules\Logistics\Events\ConsignmentConsumptionPosted;
use Modules\Logistics\Models\ConsignmentConsumptionLine;
use Modules\Logistics\Models\ConsignmentConsumptionReport;
use Modules\Logistics\Models\StockTransferOrder;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;

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
            $movements = StockMovement::query()->where('warehouse_id', $warehouse->id)->orderBy('executed_at')->get();
            $productIds = $movements->pluck('product_id')->filter()->unique()->values();
            $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');

            $rows = [];
            foreach ($productIds as $productId) {
                $own = $movements->where('product_id', $productId);
                $inPeriod = $own->filter(fn ($m): bool => ($from === null || $m->executed_at >= $from) && ($to === null || $m->executed_at <= $to.' 23:59:59'));
                $before = $from === null ? collect() : $own->filter(fn ($m): bool => $m->executed_at < $from);

                $opening = $before->sum(fn ($m): float => $this->signed($m));
                $sent = $inPeriod->filter(fn ($m): bool => $this->isInbound((string) $m->movement_type))->sum(fn ($m): float => (float) $m->quantity);
                $consumed = $inPeriod->where('movement_type', 'consignment_consumption_out')->sum(fn ($m): float => (float) $m->quantity);
                $returned = $inPeriod->filter(fn ($m): bool => str_ends_with((string) $m->movement_type, '_out') && $m->movement_type !== 'consignment_consumption_out')->sum(fn ($m): float => (float) $m->quantity);
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
                    'ddt_references' => $this->ddtReferences($inPeriod->filter(fn ($m): bool => $this->isInbound((string) $m->movement_type) && $m->origin_document_id !== null)),
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
     * Declares consumption and posts it: stock leaves the consignment warehouse for good and a draft sales invoice
     * (customer consignment) or a draft purchase order (vendor consignment) is generated.
     *
     * @param  array{warehouse_id: string, period_start: string, period_end: string, notes?: ?string}  $data
     * @param  list<array{product_id: string, quantity: float|int|string, unit_price?: float|int|string|null, tax_rate?: float|int|string|null}>  $lines
     */
    public function declareConsumption(array $data, array $lines): ConsignmentConsumptionReport
    {
        if ($lines === []) {
            throw new InvalidArgumentException('Declare at least one consumed product.');
        }

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);
        $direction = match ($warehouse->warehouse_type) {
            WarehouseType::CustomerConsignment->value => 'customer',
            WarehouseType::VendorConsignment->value => 'vendor',
            default => throw new DomainException('Consumption can only be declared on customer or vendor consignment warehouses.'),
        };
        if ($warehouse->linked_partner_id === null) {
            throw new DomainException('The consignment warehouse has no linked partner to bill.');
        }

        return DB::transaction(function () use ($data, $lines, $warehouse, $direction): ConsignmentConsumptionReport {
            $report = ConsignmentConsumptionReport::create([
                'tenant_id' => $this->tenantContext->getTenantId(),
                'warehouse_id' => $warehouse->id,
                'partner_id' => $warehouse->linked_partner_id,
                'direction' => $direction,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
            ]);

            $documentLines = [];
            foreach ($lines as $line) {
                $qty = (float) $line['quantity'];
                $product = Product::query()->findOrFail($line['product_id']);
                $defaultPrice = (float) ($direction === 'customer' ? $product->sale_price : $product->cost_price);
                $price = isset($line['unit_price']) ? (float) $line['unit_price'] : $defaultPrice;
                $tax = isset($line['tax_rate']) ? (float) $line['tax_rate'] : (float) $product->tax_rate;

                $this->stock->move((string) $product->id, (string) $warehouse->id, null, $qty, 'consignment_consumption', ConsignmentConsumptionReport::class, (string) $report->id, unitCost: (float) $product->cost_price);

                ConsignmentConsumptionLine::create([
                    'tenant_id' => $report->tenant_id,
                    'report_id' => $report->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'tax_rate' => $tax,
                ]);
                $documentLines[] = ['product' => $product, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => $tax];
            }

            [$type, $documentId, $total] = $direction === 'customer'
                ? $this->draftSalesInvoice($report, $documentLines)
                : $this->draftPurchaseOrder($report, $documentLines);

            $report->update([
                'status' => 'posted',
                'generated_document_type' => $type,
                'generated_document_id' => $documentId,
                'total_amount' => $total,
            ]);

            event(new ConsignmentConsumptionPosted((string) $report->id, $direction, $documentId, (string) $report->tenant_id));

            return $report->load('lines');
        });
    }

    /**
     * Inventory value for the balance sheet: company-owned stock (including goods at customers, subcontractors and in
     * transit) is assets; vendor-owned consignment stock physically in our premises is excluded.
     *
     * @return array{total_balance_sheet_value: float, excluded_third_party_value: float, by_warehouse_type: array<string, float>, by_warehouse: list<array<string, mixed>>}
     */
    public function inventoryValuation(): array
    {
        $tenantId = $this->tenantContext->getTenantId();

        $rows = DB::table('warehouse_stock_levels as l')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->where('l.tenant_id', $tenantId)
            ->whereNull('l.deleted_at')
            ->whereNull('w.deleted_at')
            ->selectRaw('w.id as warehouse_id, w.code as warehouse_code, w.warehouse_type as warehouse_type, w.legal_owner_type as legal_owner_type, SUM(l.quantity_on_hand * p.cost_price) as value')
            ->groupBy('w.id', 'w.code', 'w.warehouse_type', 'w.legal_owner_type')
            ->get();

        $balanceSheet = 0.0;
        $excluded = 0.0;
        $byType = [];
        $byWarehouse = [];
        foreach ($rows as $row) {
            $value = round((float) $row->value, 2);
            $onBalanceSheet = $row->legal_owner_type === LegalOwnerType::Company->value;
            if ($onBalanceSheet) {
                $balanceSheet += $value;
                $byType[$row->warehouse_type] = round(($byType[$row->warehouse_type] ?? 0.0) + $value, 2);
            } else {
                $excluded += $value;
            }
            $byWarehouse[] = [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->warehouse_code,
                'warehouse_type' => $row->warehouse_type,
                'legal_owner_type' => $row->legal_owner_type,
                'value' => $value,
                'on_balance_sheet' => $onBalanceSheet,
            ];
        }

        return [
            'total_balance_sheet_value' => round($balanceSheet, 2),
            'excluded_third_party_value' => round($excluded, 2),
            'by_warehouse_type' => $byType,
            'by_warehouse' => $byWarehouse,
        ];
    }

    /**
     * @param  list<array{product: Product, quantity: float, unit_price: float, tax_rate: float}>  $documentLines
     * @return array{0: string, 1: string, 2: float}
     */
    private function draftSalesInvoice(ConsignmentConsumptionReport $report, array $documentLines): array
    {
        $items = array_map(fn (array $l): array => [
            'product_id' => (string) $l['product']->id,
            'description' => sprintf('%s (consumo conto deposito %s - %s)', $l['product']->name, $report->period_start->format('d/m/Y'), $report->period_end->format('d/m/Y')),
            'quantity' => $l['quantity'],
            'unit_price' => $l['unit_price'],
            'tax_rate' => $l['tax_rate'],
        ], $documentLines);

        $invoice = $this->invoicing->createInvoice([
            'tenant_id' => (string) $report->tenant_id,
            'contact_id' => (string) $report->partner_id,
            'notes' => 'Fattura a consumo conto deposito',
            'created_by' => auth()->id(),
        ], $items);

        return ['sales_invoice', (string) $invoice->id, (float) $invoice->total_gross];
    }

    /**
     * @param  list<array{product: Product, quantity: float, unit_price: float, tax_rate: float}>  $documentLines
     * @return array{0: string, 1: string, 2: float}
     */
    private function draftPurchaseOrder(ConsignmentConsumptionReport $report, array $documentLines): array
    {
        $order = app(ProcurementServiceInterface::class)->createPurchaseOrder(new CreatePurchaseOrderDTO(
            supplierId: (string) $report->partner_id,
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
            tenantId: (string) $report->tenant_id,
            creatorUserId: auth()->id() !== null ? (string) auth()->id() : null,
        ));

        return ['purchase_order', (string) $order->id, (float) $order->total_gross];
    }

    private function isInbound(string $movementType): bool
    {
        return $movementType === WarehouseStockService::INBOUND_REVERSAL || str_ends_with($movementType, '_in');
    }

    private function signed(StockMovement $movement): float
    {
        $qty = (float) $movement->quantity;

        return $this->isInbound((string) $movement->movement_type) ? $qty : -$qty;
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return list<string>
     */
    private function ddtReferences($movements): array
    {
        $numbers = [];
        foreach ($movements as $movement) {
            if ($movement->origin_document_type === SalesDeliveryNote::class) {
                $number = SalesDeliveryNote::query()->whereKey($movement->origin_document_id)->value('ddt_number');
            } elseif ($movement->origin_document_type === StockTransferOrder::class) {
                $noteId = StockTransferOrder::query()->whereKey($movement->origin_document_id)->value('delivery_note_id');
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
