<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use App\Models\Product;
use App\Services\Inventory\StockService;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\ServiceManagement\FieldService\CoveragePolicy;
use Modules\ServiceManagement\FieldService\VanWarehouseResolver;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Services\ServiceAmounts;

/**
 * Deducts a used part from the technician's van warehouse through the inventory movement engine and records whether the
 * line is covered by the maintenance contract / warranty (price shown as 0.00 on the report) or billable. Idempotent on
 * `client_uuid`: replaying an offline sync returns the first consumption and moves no stock twice.
 */
final readonly class RecordVanStockPartConsumptionHandler
{
    public function __construct(
        private StockService $stock,
        private VanWarehouseResolver $vans,
        private CoveragePolicy $coverage,
        private AuditLedgerInterface $auditLedger,
    ) {}

    public function __invoke(RecordVanStockPartConsumptionCommand $command): PartsConsumption
    {
        return DB::transaction(function () use ($command): PartsConsumption {
            $workOrder = WorkOrder::query()->whereKey($command->workOrderId)->lockForUpdate()->firstOrFail();

            if ($command->clientUuid !== null) {
                $existing = PartsConsumption::query()->where('tenant_id', $workOrder->tenant_id)->where('client_uuid', $command->clientUuid)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            if (! in_array($workOrder->status, ['dispatched', 'en_route', 'on_site', 'in_progress'], true)) {
                throw ValidationException::withMessages(['work_order' => __('field_service.errors.parts_not_open')]);
            }

            $quantity = ServiceAmounts::text($command->quantity);

            if (! Decimal::isPositive($quantity)) {
                throw ValidationException::withMessages(['quantity' => __('work_orders.part_quantity_positive')]);
            }

            $technician = $workOrder->assigned_technician_id === null
                ? null
                : ServiceTechnician::query()->where('tenant_id', $workOrder->tenant_id)->find($workOrder->assigned_technician_id);
            $van = $technician === null ? null : $this->vans->forTechnician($technician);

            if ($van === null) {
                throw ValidationException::withMessages(['van_warehouse' => __('field_service.errors.no_van')]);
            }

            $product = Product::query()->where('tenant_id', $workOrder->tenant_id)->findOrFail($command->productId);

            $contract = $workOrder->service_contract_id === null
                ? null
                : ServiceContract::query()->where('tenant_id', $workOrder->tenant_id)->find($workOrder->service_contract_id);
            $covered = $this->coverage->coversParts($workOrder, $contract);

            $movement = $product->track_inventory
                ? $this->stock->record((string) $product->id, 'out', (float) $quantity, $workOrder, $van, (float) ($product->cost_price ?? 0), "Field work order {$workOrder->work_order_number}")
                : null;

            $unitCost = ServiceAmounts::money($product->cost_price ?? '0');
            $unitPrice = $covered ? ServiceAmounts::money('0') : ServiceAmounts::money($command->unitPrice);
            $totalCost = ServiceAmounts::lineTotal($quantity, $unitCost);
            $totalPrice = ServiceAmounts::lineTotal($quantity, $unitPrice);

            $consumption = PartsConsumption::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'work_order_id' => $workOrder->id,
                'product_id' => $product->id,
                'warehouse_id' => $van->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'unit_price' => $unitPrice,
                'is_covered_by_warranty_or_contract' => $covered,
                'stock_movement_id' => $movement?->id,
                'lot_or_serial_id' => $command->lotOrSerialId,
                'lot_number' => $command->lotNumber ?? (string) $product->sku,
                'client_uuid' => $command->clientUuid,
                'metadata' => ['description' => (string) $product->name, 'item_number' => (string) $product->sku, 'list_unit_price' => ServiceAmounts::money($command->unitPrice)],
            ]);

            $workOrder->material_cost = ServiceAmounts::add($workOrder->material_cost, $totalCost);
            $workOrder->total_cost = ServiceAmounts::add($workOrder->total_cost, $totalCost);
            $workOrder->total_billable_amount = ServiceAmounts::add($workOrder->total_billable_amount, $totalPrice);
            $workOrder->save();

            $this->auditLedger->record('VAN_PART_CONSUMED', $consumption, null, ['product_id' => (string) $product->id, 'quantity' => $quantity, 'covered' => $covered], 'MODULE_17_ESM');

            return $consumption;
        });
    }
}
