<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\StockItem;

/**
 * Works out cartons, pallets and weights from the packaging data of the item master. Quantities are exact; a carton or
 * pallet that is not full still counts as one. An item without packaging data stays loose, weighing its goods only.
 */
final class PackagingCalculator
{
    /**
     * @return array{stock_item_id: string, quantity: string, units_per_carton: ?int, cartons: int, pallets: int, net_weight_kg: string, tare_weight_kg: string, gross_weight_kg: string, volume_m3: string}
     */
    public function forItem(StockItem $item, string $quantity): array
    {
        $unitsPerCarton = $item->units_per_carton;
        $cartons = $unitsPerCarton !== null ? $this->ceilDivide($quantity, (string) $unitsPerCarton) : 0;
        $pallets = $cartons > 0 && $item->cartons_per_pallet !== null ? $this->ceilDivide((string) $cartons, (string) $item->cartons_per_pallet) : 0;

        $net = bcmul($quantity, (string) ($item->net_weight_kg ?? $item->weight_kg ?? '0'), 3);
        $tare = bcadd(
            bcmul((string) $cartons, (string) ($item->carton_tare_kg ?? '0'), 3),
            bcmul((string) $pallets, (string) ($item->pallet_tare_kg ?? '0'), 3),
            3
        );

        return [
            'stock_item_id' => (string) $item->id,
            'quantity' => bcadd($quantity, '0', 4),
            'units_per_carton' => $unitsPerCarton,
            'cartons' => $cartons,
            'pallets' => $pallets,
            'net_weight_kg' => $net,
            'tare_weight_kg' => $tare,
            'gross_weight_kg' => bcadd($net, $tare, 3),
            'volume_m3' => bcmul($quantity, (string) ($item->volume_m3 ?? '0'), 5),
        ];
    }

    /**
     * @param  list<array{item: StockItem, quantity: string}>  $lines
     * @return array{lines: list<array<string, mixed>>, cartons: int, pallets: int, net_weight_kg: string, gross_weight_kg: string, volume_m3: string}
     */
    public function forLines(array $lines): array
    {
        $totals = ['lines' => [], 'cartons' => 0, 'pallets' => 0, 'net_weight_kg' => '0.000', 'gross_weight_kg' => '0.000', 'volume_m3' => '0.00000'];

        foreach ($lines as $line) {
            $plan = $this->forItem($line['item'], $line['quantity']);
            $totals['lines'][] = $plan;
            $totals['cartons'] += $plan['cartons'];
            $totals['pallets'] += $plan['pallets'];
            $totals['net_weight_kg'] = bcadd($totals['net_weight_kg'], $plan['net_weight_kg'], 3);
            $totals['gross_weight_kg'] = bcadd($totals['gross_weight_kg'], $plan['gross_weight_kg'], 3);
            $totals['volume_m3'] = bcadd($totals['volume_m3'], $plan['volume_m3'], 5);
        }

        return $totals;
    }

    private function ceilDivide(string $dividend, string $divisor): int
    {
        return (int) bcceil(bcdiv($dividend, $divisor, 8));
    }
}
