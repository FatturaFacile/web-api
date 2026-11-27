<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Domain\Enums\SubcontractingType;
use Modules\Subcontracting\Exceptions\SubcontractingException;

/**
 * Plans the input lines of a subcontracting order from the manufacturing master data: the whole BOM (Mod 060) for a full-BOM
 * job, or only the components consumed by the outsourced routing step (Mod 061/064) for a single-operation job.
 *
 * @phpstan-type PlannedLine array{component_product_id: string, unit_of_measure: string, required_qty_per_unit: string, lot_id?: string|null, serial_number?: string|null}
 */
final class BomComponentResolver
{
    /**
     * @return array{lines: list<PlannedLine>, bom_id: string|null, operation_sequence: int|null, operation_label: string|null}
     */
    public function resolve(SubcontractingType $type, string $tenantId, ?string $bomId, ?string $productionOrderId, ?string $operationId): array
    {
        $operation = null;
        $order = null;

        if ($productionOrderId !== null) {
            $order = MfgProductionOrder::query()->where('tenant_id', $tenantId)->whereKey($productionOrderId)->first();

            if (! $order instanceof MfgProductionOrder) {
                throw new SubcontractingException('production_order_not_found');
            }

            $bomId ??= (string) $order->bom_id;
        }

        if ($operationId !== null) {
            $operation = MfgProductionOrderOperation::query()
                ->where('tenant_id', $tenantId)
                ->when($productionOrderId !== null, static fn ($q) => $q->where('production_order_id', $productionOrderId))
                ->whereKey($operationId)
                ->first();

            if (! $operation instanceof MfgProductionOrderOperation) {
                throw new SubcontractingException('production_operation_not_found');
            }
        }

        if ($bomId === null) {
            return ['lines' => [], 'bom_id' => null, 'operation_sequence' => $operation?->sequence, 'operation_label' => $this->label($operation)];
        }

        $bom = MfgBom::query()->with('components.componentItem')->where('tenant_id', $tenantId)->whereKey($bomId)->first();

        if (! $bom instanceof MfgBom) {
            throw new SubcontractingException('bom_not_found');
        }

        $base = Money::isPositive($bom->base_quantity) ? (string) $bom->base_quantity : '1';
        $lines = [];

        foreach ($bom->components as $component) {
            if ($component->is_phantom) {
                continue;
            }
            if ($type === SubcontractingType::SingleOperation && $operation !== null && $component->operation_sequence !== null && (int) $component->operation_sequence !== (int) $operation->sequence) {
                continue;
            }

            $lines[] = $this->line($component, $base);
        }

        return ['lines' => $lines, 'bom_id' => (string) $bom->id, 'operation_sequence' => $operation?->sequence, 'operation_label' => $this->label($operation)];
    }

    /**
     * @return PlannedLine
     */
    private function line(MfgBomComponent $component, string $base): array
    {
        $productId = $component->componentItem?->product_id;

        if ($productId === null) {
            throw new SubcontractingException('component_without_product', 422, ['item' => (string) ($component->componentItem->item_code ?? $component->component_item_id)]);
        }

        // Quantity per finished unit, grossed up by the component scrap percentage of the BOM line.
        $perUnit = Money::div((string) $component->quantity_per, $base);
        $perUnit = Money::mul($perUnit, Money::add('1', Money::div((string) $component->scrap_percentage, '100')));

        return [
            'component_product_id' => (string) $productId,
            'unit_of_measure' => (string) $component->uom,
            'required_qty_per_unit' => Money::round($perUnit, 6),
        ];
    }

    private function label(?MfgProductionOrderOperation $operation): ?string
    {
        if (! $operation instanceof MfgProductionOrderOperation) {
            return null;
        }

        return trim(($operation->operation_code !== null ? $operation->operation_code.' ' : '').(string) $operation->name) ?: null;
    }
}
