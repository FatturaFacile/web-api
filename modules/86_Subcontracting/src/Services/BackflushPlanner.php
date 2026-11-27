<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Modules\Manufacturing\Support\Money;
use Modules\Subcontracting\Exceptions\SubcontractingException;
use Modules\Subcontracting\Models\SubcontractingOrder;
use Modules\Subcontracting\Models\SubcontractingOrderComponent;

/**
 * Works out what a receipt of processed goods consumes: per component the quantity backflushed for the good units (BOM or routing
 * ratio, or the actual quantity the user overrides it with), the scrap declared, their value, the processing fee and the unit
 * cost the goods are capitalized at (Mod 059 / Mod 067).
 *
 * @phpstan-type ReceiptLine array{component_id: string, product_id: string, consumed: string, scrap: string, unit_cost: string, value: string}
 * @phpstan-type ReceiptPlan array{good_quantity: string, scrap_quantity: string, lines: list<ReceiptLine>, components_value: string, service_amount: string, total_cost: string, unit_cost: string}
 */
final class BackflushPlanner
{
    public function __construct(private readonly SubcontractStockMover $stock) {}

    /**
     * @param  Collection<string, SubcontractingOrderComponent>  $components
     * @param  array<string, mixed>  $data  good_quantity, scrap_quantity and the optional per-component overrides (component_id, consumed_qty, scrap_qty)
     * @return ReceiptPlan
     */
    public function plan(SubcontractingOrder $order, Collection $components, array $data): array
    {
        $good = Money::qty($data['good_quantity'] ?? '0');
        $scrapUnits = Money::qty($data['scrap_quantity'] ?? '0');

        if (Money::isNegative($good) || Money::isNegative($scrapUnits) || ! Money::isPositive(Money::add($good, $scrapUnits))) {
            throw new SubcontractingException('quantity_must_be_positive');
        }
        if (Money::compare(Money::add($good, $scrapUnits), $order->openQuantity()) > 0) {
            throw new SubcontractingException('receipt_exceeds_planned', 422, ['open' => $order->openQuantity()]);
        }

        /** @var array<string, array{consumed_qty?: string, scrap_qty?: string}> $overrides */
        $overrides = [];
        foreach ($data['components'] ?? [] as $row) {
            $overrides[(string) $row['component_id']] = $row;
        }
        foreach (array_keys($overrides) as $componentId) {
            if (! $components->has($componentId)) {
                throw new SubcontractingException('component_not_in_order');
            }
        }

        $lines = [];
        $componentsValue = '0';

        foreach ($components as $component) {
            $override = $overrides[(string) $component->id] ?? [];
            $consumed = Money::qty($override['consumed_qty'] ?? Money::mul($component->required_qty_per_unit, $good));
            $scrap = Money::qty($override['scrap_qty'] ?? Money::mul($component->required_qty_per_unit, $scrapUnits));

            if (Money::isNegative($consumed) || Money::isNegative($scrap)) {
                throw new SubcontractingException('quantity_must_be_positive');
            }
            if (Money::compare(Money::add($consumed, $scrap), $component->heldQuantity()) > 0) {
                throw new SubcontractingException('consumption_exceeds_held', 422, ['product' => $this->productName($component->component_product_id), 'held' => $component->heldQuantity()]);
            }

            $unitCost = $this->stock->unitCost($component->component_product_id);
            $value = Money::round(Money::mul(Money::add($consumed, $scrap), $unitCost), 4);
            $componentsValue = Money::add($componentsValue, $value);
            $lines[] = ['component_id' => (string) $component->id, 'product_id' => $component->component_product_id, 'consumed' => $consumed, 'scrap' => $scrap, 'unit_cost' => $unitCost, 'value' => $value];
        }

        // The fee of the external processing is capitalized into the cost of the good units, together with the material consumed and
        // the normal scrap, so the received goods enter stock at their full cost.
        $service = Money::round(Money::mul($order->unit_service_cost, $good), 4);
        $total = Money::add($componentsValue, $service);

        return [
            'good_quantity' => $good,
            'scrap_quantity' => $scrapUnits,
            'lines' => $lines,
            'components_value' => Money::round($componentsValue, 4),
            'service_amount' => $service,
            'total_cost' => Money::round($total, 4),
            'unit_cost' => Money::isPositive($good) ? Money::round(Money::div($total, $good), 6) : '0.000000',
        ];
    }

    public function productName(string $productId): string
    {
        return (string) (Product::query()->whereKey($productId)->value('name') ?? $productId);
    }
}
