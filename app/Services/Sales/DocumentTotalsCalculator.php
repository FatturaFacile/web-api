<?php

declare(strict_types=1);

namespace App\Services\Sales;

class DocumentTotalsCalculator
{
    /**
     * Compute line totals and document net/tax/gross from raw item payloads.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, total_net: float, total_tax: float, total_gross: float}
     */
    public function calculate(array $items): array
    {
        $totalNet = 0.0;
        $totalTax = 0.0;
        $computed = [];

        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $discount = (float) ($item['discount_percentage'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            $lineNet = round($quantity * $unitPrice * (1 - $discount / 100.0), 2);
            $totalNet += $lineNet;
            $totalTax += round($lineNet * ($taxRate / 100.0), 2);

            $computed[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'discount_percentage' => $discount,
                'line_total' => $lineNet,
            ];
        }

        return [
            'items' => $computed,
            'total_net' => round($totalNet, 2),
            'total_tax' => round($totalTax, 2),
            'total_gross' => round($totalNet + $totalTax, 2),
        ];
    }
}
