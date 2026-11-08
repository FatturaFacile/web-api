<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\Events\StandardCostChangedEvent;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;

/**
 * Multi-level standard cost rollup. A purchased item (or one without an effective BOM) costs its standard cost as
 * material. A manufactured item costs, per unit of the BOM base quantity: its components at their own rolled-up
 * standard cost (material), the labour and overhead of its effective routing at the work centre rates and the agreed
 * cost of its subcontracted operations. Every manufactured level is stored on its item.
 */
final readonly class StandardCostService
{
    public function __construct(
        private BillOfMaterialsServiceInterface $boms,
        private RoutingServiceInterface $routings,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * Rolls up and stores the standard cost of the item and its manufactured components.
     *
     * @return array{mfg_item_id: string, material: string, labor: string, overhead: string, subcontract: string, total: string, components: list<array{component_item_id: string, quantity: string, unit_cost: string, extended_cost: string}>}
     */
    public function rollup(MfgItem $item, ?string $date = null): array
    {
        return DB::transaction(fn (): array => $this->rollupItem($item, $date, []));
    }

    /**
     * @param  array<string, bool>  $path
     * @return array{mfg_item_id: string, material: string, labor: string, overhead: string, subcontract: string, total: string, components: list<array{component_item_id: string, quantity: string, unit_cost: string, extended_cost: string}>}
     */
    private function rollupItem(MfgItem $item, ?string $date, array $path): array
    {
        if (isset($path[(string) $item->id])) {
            throw new ManufacturingOperationException("Item [{$item->item_code}] is a component of itself.", 'components');
        }

        $bom = $item->procurementType() === 'buy' ? null : $this->boms->effectiveBomFor($item, $date);
        if ($bom === null) {
            $unit = Money::round($item->standard_cost, 4);

            return ['mfg_item_id' => (string) $item->id, 'material' => $unit, 'labor' => '0.0000', 'overhead' => '0.0000', 'subcontract' => '0.0000', 'total' => $unit, 'components' => []];
        }

        $baseQuantity = Money::isPositive($bom->base_quantity) ? Money::of($bom->base_quantity) : '1';
        $material = '0';
        $components = [];

        foreach ($this->boms->componentRequirements($bom, $baseQuantity, $date) as $row) {
            $component = MfgItem::query()->where('tenant_id', $item->tenant_id)->findOrFail($row['component_item_id']);
            $componentCost = $this->rollupItem($component, $date, [...$path, (string) $item->id => true])['total'];
            $extended = Money::mul($row['effective_quantity'], $componentCost);
            $material = Money::add($material, $extended);
            $components[] = [
                'component_item_id' => (string) $component->id,
                'quantity' => Money::qty(Money::div($row['effective_quantity'], $baseQuantity)),
                'unit_cost' => Money::round($componentCost, 4),
                'extended_cost' => Money::round(Money::div($extended, $baseQuantity), 4),
            ];
        }

        $routing = $this->routings->effectiveRoutingFor($item, $date);
        $conversion = $routing !== null ? $this->routings->standardTimesAndCosts($routing, $baseQuantity) : ['labor_cost' => '0', 'overhead_cost' => '0', 'subcontract_cost' => '0'];

        $result = [
            'mfg_item_id' => (string) $item->id,
            'material' => Money::round(Money::div($material, $baseQuantity), 4),
            'labor' => Money::round(Money::div($conversion['labor_cost'], $baseQuantity), 4),
            'overhead' => Money::round(Money::div($conversion['overhead_cost'], $baseQuantity), 4),
            'subcontract' => Money::round(Money::div($conversion['subcontract_cost'], $baseQuantity), 4),
            'total' => '0',
            'components' => $components,
        ];
        $result['total'] = Money::round(Money::add(Money::add($result['material'], $result['labor']), Money::add($result['overhead'], $result['subcontract'])), 4);

        $previous = Money::round($item->standard_cost, 4);
        $item->update([
            'standard_material_cost' => $result['material'],
            'standard_labor_cost' => $result['labor'],
            'standard_overhead_cost' => $result['overhead'],
            'standard_subcontract_cost' => $result['subcontract'],
            'standard_cost' => $result['total'],
            'standard_cost_rolled_up_at' => now(),
        ]);

        if (Money::compare($previous, $result['total']) !== 0) {
            $this->dispatcher->publishAndDispatch(new StandardCostChangedEvent(
                mfgItemId: (string) $item->id,
                oldCost: Money::toFloat($previous),
                newCost: Money::toFloat($result['total']),
                reason: 'Standard cost rollup',
                tenantId: (string) $item->tenant_id
            ));
        }

        return $result;
    }
}
