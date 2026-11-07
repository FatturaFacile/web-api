<?php

declare(strict_types=1);

namespace App\Services\Sales;

class DocumentTotalsCalculator
{
    public function __construct(private readonly VatSummaryCalculator $vat = new VatSummaryCalculator) {}

    /**
     * Compute line totals and document net/tax/gross from raw item payloads. The tax is computed per VAT rate on the
     * summed net of its lines, like the summary of the electronic invoice.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, total_net: float, total_tax: float, total_gross: float}
     */
    public function calculate(array $items): array
    {
        $totalNet = 0.0;
        $vatEntries = [];
        $computed = [];

        foreach ($items as $item) {
            $quantity = (float) $item['quantity'];
            $unitPrice = (float) $item['unit_price'];
            $discount = (float) ($item['discount_percentage'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            $lineNet = round($quantity * $unitPrice * (1 - $discount / 100.0), 2);
            $totalNet += $lineNet;
            $vatEntries[] = ['rate' => $taxRate, 'taxable' => $lineNet];

            $computed[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'discount_percentage' => $discount,
                'line_total' => $lineNet,
            ] + array_intersect_key($item, ['revenue_account_id' => true, 'product_charge_type_id' => true]);
        }

        $totalTax = (float) $this->vat->totalTax($vatEntries);

        return [
            'items' => $computed,
            'total_net' => round($totalNet, 2),
            'total_tax' => $totalTax,
            'total_gross' => round($totalNet + $totalTax, 2),
        ];
    }
}
