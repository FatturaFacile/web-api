<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Inventory\Models\Warehouse;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\CustomsProcessingRegime;
use Modules\Subcontracting\Domain\Enums\Direction;
use Modules\Subcontracting\Domain\Enums\OrderStatus;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;

/**
 * Creation, confirmation and cancellation of subcontracting orders.
 *
 * @phpstan-import-type PlannedLine from BomComponentResolver
 */
final class SubcontractingOrderService
{
    public function __construct(
        private readonly VirtualWarehouseProvisioner $warehouses,
        private readonly BomComponentResolver $resolver,
        private readonly ServicePurchaseOrderGateway $purchaseOrders,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated payload of CreateSubcontractingOrderRequest
     */
    public function create(array $data, string $tenantId, ?string $actorId): SubcontractingOrder
    {
        $direction = Direction::from((string) $data['direction']);
        $type = SubcontractingType::from((string) $data['subcontracting_type']);
        $regime = CustomsProcessingRegime::from((string) ($data['customs_regime'] ?? CustomsProcessingRegime::None->value));
        $planned = Money::qty($data['planned_quantity']);

        if (! Money::isPositive($planned)) {
            throw new SubcontractingException('quantity_must_be_positive');
        }

        $this->assertRegimeMatchesDirection($direction, $regime);

        if (! Product::query()->whereKey($data['output_product_id'])->exists()) {
            throw new SubcontractingException('product_not_found');
        }
        if (($data['customs_declaration_id'] ?? null) !== null && ! CustomsDeclaration::query()->whereKey($data['customs_declaration_id'])->exists()) {
            throw new SubcontractingException('customs_declaration_not_found');
        }
        if ($direction === Direction::Passive && ($data['linked_service_purchase_order_id'] ?? null) !== null) {
            $this->purchaseOrders->assertLinkable($tenantId, (string) $data['linked_service_purchase_order_id'], (string) $data['partner_id']);
        }

        $planning = $this->resolver->resolve($type, $tenantId, $data['bom_id'] ?? null, $data['linked_production_order_id'] ?? null, $data['linked_production_order_operation_id'] ?? null);
        $lines = $this->plannedLines($data['components'] ?? null, $planning['lines']);

        if ($lines === []) {
            throw new SubcontractingException('no_components');
        }

        return DB::transaction(function () use ($data, $direction, $type, $regime, $planned, $lines, $planning, $tenantId, $actorId): SubcontractingOrder {
            ['warehouse' => $virtual, 'partner_name' => $partnerName] = $this->warehouses->provision($direction, (string) $data['partner_id'], $tenantId);
            $source = $this->companyWarehouse($tenantId, $data['source_warehouse_id'] ?? null);

            $order = SubcontractingOrder::create([
                'tenant_id' => $tenantId,
                'order_number' => $this->nextNumber($tenantId),
                'direction' => $direction->value,
                'subcontracting_type' => $type->value,
                'status' => OrderStatus::Draft->value,
                'partner_type' => $direction->partnerType(),
                'partner_id' => $data['partner_id'],
                'partner_name' => $partnerName,
                'source_warehouse_id' => $source->id,
                'subcontractor_warehouse_id' => $virtual->id,
                'bom_id' => $planning['bom_id'],
                'linked_production_order_id' => $data['linked_production_order_id'] ?? null,
                'linked_production_order_operation_id' => $data['linked_production_order_operation_id'] ?? null,
                'routing_operation_label' => $data['routing_operation_label'] ?? $planning['operation_label'],
                'linked_service_purchase_order_id' => $data['linked_service_purchase_order_id'] ?? null,
                'linked_customer_sales_order_id' => $data['linked_customer_sales_order_id'] ?? null,
                'output_product_id' => $data['output_product_id'],
                'planned_quantity' => $planned,
                'unit_service_cost' => Money::round($data['unit_service_cost'] ?? '0', 4),
                'currency' => strtoupper((string) ($data['currency'] ?? 'EUR')),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'customs_regime' => $regime->value,
                'customs_declaration_id' => $data['customs_declaration_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                SubcontractingOrderComponent::create([
                    'tenant_id' => $tenantId,
                    'subcontracting_order_id' => $order->id,
                    'component_product_id' => $line['component_product_id'],
                    'unit_of_measure' => $line['unit_of_measure'],
                    'required_qty_per_unit' => $line['required_qty_per_unit'],
                    'total_planned_qty' => Money::qty(Money::mul($line['required_qty_per_unit'], $planned)),
                    'lot_id' => $line['lot_id'] ?? null,
                    'serial_number' => $line['serial_number'] ?? null,
                    'created_by' => $actorId,
                ]);
            }

            if ($direction === Direction::Passive && ($data['generate_service_purchase_order'] ?? false) && $order->linked_service_purchase_order_id === null) {
                $order->update(['linked_service_purchase_order_id' => $this->purchaseOrders->draftFor($order)->id]);
            }

            return $order->load('components');
        });
    }

    public function confirm(SubcontractingOrder $order): SubcontractingOrder
    {
        return DB::transaction(function () use ($order): SubcontractingOrder {
            $locked = $this->lock($order);

            if ($locked->status !== OrderStatus::Draft) {
                throw new SubcontractingException('invalid_status_transition', 422, ['from' => $locked->status->value, 'to' => OrderStatus::Confirmed->value]);
            }

            $locked->update(['status' => OrderStatus::Confirmed->value, 'confirmed_at' => now()]);

            return $locked->load('components');
        });
    }

    /** Only an order that has not moved any goods can be cancelled. */
    public function cancel(SubcontractingOrder $order): SubcontractingOrder
    {
        return DB::transaction(function () use ($order): SubcontractingOrder {
            $locked = $this->lock($order);

            if (! in_array($locked->status, [OrderStatus::Draft, OrderStatus::Confirmed], true)) {
                throw new SubcontractingException('cancel_after_movement');
            }

            $locked->update(['status' => OrderStatus::Cancelled->value]);

            return $locked->load('components');
        });
    }

    public function lock(SubcontractingOrder $order): SubcontractingOrder
    {
        return SubcontractingOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
    }

    private function assertRegimeMatchesDirection(Direction $direction, CustomsProcessingRegime $regime): void
    {
        $valid = match ($regime) {
            CustomsProcessingRegime::None => true,
            CustomsProcessingRegime::TppOutwardProcessing => $direction === Direction::Passive,
            CustomsProcessingRegime::TpaInwardProcessing => $direction === Direction::Active,
        };

        if (! $valid) {
            throw new SubcontractingException('customs_regime_direction_mismatch');
        }
    }

    /**
     * Explicit lines win over the master data; either way every product must exist.
     *
     * @param  list<array<string, mixed>>|null  $explicit
     * @param  list<PlannedLine>  $fromBom
     * @return list<PlannedLine>
     */
    private function plannedLines(?array $explicit, array $fromBom): array
    {
        $lines = $explicit !== null && $explicit !== []
            ? array_map(static fn (array $row): array => [
                'component_product_id' => (string) $row['component_product_id'],
                'unit_of_measure' => (string) ($row['unit_of_measure'] ?? 'PCS'),
                'required_qty_per_unit' => Money::round($row['required_qty_per_unit'], 6),
                'lot_id' => $row['lot_id'] ?? null,
                'serial_number' => $row['serial_number'] ?? null,
            ], $explicit)
            : $fromBom;

        $ids = array_values(array_unique(array_map(static fn (array $line): string => $line['component_product_id'], $lines)));

        if ($ids !== [] && Product::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw new SubcontractingException('product_not_found');
        }

        return $lines;
    }

    private function companyWarehouse(string $tenantId, mixed $warehouseId): Warehouse
    {
        $query = Warehouse::query()->where('tenant_id', $tenantId)->where('legal_owner_type', 'company')->where('is_virtual', false);
        $warehouse = $warehouseId !== null ? $query->whereKey($warehouseId)->first() : $query->orderByDesc('is_primary')->orderBy('created_at')->first();

        if (! $warehouse instanceof Warehouse) {
            throw new SubcontractingException('warehouse_not_found');
        }

        return $warehouse;
    }

    private function nextNumber(string $tenantId): string
    {
        $prefix = 'SCT-'.now()->format('Y').'-';
        $last = SubcontractingOrder::withTrashed()->where('tenant_id', $tenantId)->where('order_number', 'like', $prefix.'%')->orderByDesc('order_number')->value('order_number');
        $next = $last !== null ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
