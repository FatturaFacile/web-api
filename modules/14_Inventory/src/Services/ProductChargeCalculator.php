<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Models\ProductChargeAssignment;
use Modules\Inventory\Models\ProductChargeRate;

/**
 * Adds the supplementary charges of the products on a document, one separate line per charge and VAT rate. Each line
 * carries the revenue account of its charge, so posting the document credits it there. A product with a charge and no
 * rate valid on the document date stops the document: billing it without the charge would be silently wrong.
 */
final class ProductChargeCalculator
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  document lines with product_id, quantity, unit_price, tax_rate and discount_percentage
     * @return list<array{product_charge_type_id: string, description: string, quantity: float, unit_price: float, tax_rate: mixed, discount_percentage: float, revenue_account_id: ?string, uom: string, notes: string}>
     */
    public function linesFor(array $lines, string $date, string $currency, string $tenantId): array
    {
        if (collect($lines)->contains(static fn (array $line): bool => ! empty($line['product_charge_type_id']))) {
            return [];
        }

        $productIds = collect($lines)->pluck('product_id')->filter()->unique()->values();

        if ($productIds->isEmpty()) {
            return [];
        }

        $assignments = ProductChargeAssignment::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('product_id', $productIds)
            ->whereHas('type', static fn ($type) => $type->where('is_active', true))
            ->with('type')
            ->get()
            ->groupBy('product_id');

        $totals = [];

        foreach ($lines as $line) {
            foreach ($assignments->get($line['product_id'] ?? '', collect()) as $assignment) {
                $type = $assignment->type;
                $rate = $this->rateFor($assignment, $date, $currency, $tenantId);
                $amount = $this->amount($type->calculation_basis, $rate, $assignment, $line);
                $key = $type->id.'|'.$line['tax_rate'];

                $totals[$key] ??= ['type' => $type, 'tax_rate' => $line['tax_rate'], 'amount' => '0', 'rate' => $rate];
                $totals[$key]['amount'] = Decimal::add($totals[$key]['amount'], $amount);
            }
        }

        return array_values(array_map(static fn (array $total): array => [
            'product_charge_type_id' => (string) $total['type']->id,
            'description' => (string) $total['type']->name,
            'quantity' => 1.0,
            'unit_price' => (float) Decimal::round2($total['amount']),
            'tax_rate' => $total['tax_rate'],
            'discount_percentage' => 0.0,
            'revenue_account_id' => $total['type']->revenue_account_id,
            'uom' => 'EA',
            'notes' => "{$total['type']->code}: {$total['type']->calculation_basis} at {$total['rate']} {$currency}",
        ], $totals));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function amount(string $basis, string $rate, ProductChargeAssignment $assignment, array $line): string
    {
        $quantity = (string) ($line['quantity'] ?? '1');

        if ($basis === 'percent_of_net') {
            $gross = Decimal::mul($quantity, (string) ($line['unit_price'] ?? '0'));
            $net = Decimal::sub($gross, Decimal::div(Decimal::mul($gross, (string) ($line['discount_percentage'] ?? '0')), '100'));

            return Decimal::div(Decimal::mul($net, $rate), '100');
        }

        return Decimal::mul(Decimal::mul($quantity, (string) $assignment->quantity_per_unit), $rate);
    }

    private function rateFor(ProductChargeAssignment $assignment, string $date, string $currency, string $tenantId): string
    {
        $rate = ProductChargeRate::query()
            ->where('tenant_id', $tenantId)
            ->where('product_charge_type_id', $assignment->product_charge_type_id)
            ->where('currency', $currency)
            ->whereDate('valid_from', '<=', $date)
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->orderByDesc('valid_from')
            ->first();

        if ($rate === null) {
            throw new StockRuleViolation("Charge [{$assignment->type->code}] has no {$currency} rate valid on {$date}.");
        }

        return (string) $rate->rate;
    }
}
