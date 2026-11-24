<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Line and document totals of the sales and purchase documents, in decimal arithmetic. The net of a line is quantity x
 * unit price less its discount percentage, rounded to the cent half away from zero; the tax is computed per VAT rate on
 * the summed net of its lines, like the summary of the electronic invoice.
 */
class DocumentTotalsCalculator
{
    public function __construct(private readonly VatSummaryCalculator $vat = new VatSummaryCalculator) {}

    /**
     * Compute line totals and document net/tax/gross from raw item payloads, as floats.
     *
     * @deprecated Kept for the callers that store floats; the computation is the decimal one of calculateDecimal().
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, total_net: float, total_tax: float, total_gross: float}
     */
    public function calculate(array $items): array
    {
        $decimal = $this->calculateDecimal($items);
        $computed = [];

        foreach ($decimal['items'] as $index => $line) {
            $computed[] = [
                'product_id' => $items[$index]['product_id'] ?? null,
                'description' => $items[$index]['description'],
                'quantity' => (float) $line['quantity'],
                'unit_price' => (float) $line['unit_price'],
                'tax_rate' => (float) $line['tax_rate'],
                'discount_percentage' => (float) $line['discount_percentage'],
                'line_total' => (float) $line['line_total'],
            ] + array_intersect_key($items[$index], ['revenue_account_id' => true, 'product_charge_type_id' => true]);
        }

        return [
            'items' => $computed,
            'total_net' => (float) $decimal['total_net'],
            'total_tax' => (float) $decimal['total_tax'],
            'total_gross' => (float) $decimal['total_gross'],
        ];
    }

    /**
     * Line totals and document totals as decimal strings: subtotal (before discounts), discount total, net, tax, gross.
     * The items keep their index; each carries quantity, unit_price, tax_rate, discount_percentage and line_total.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array{quantity: string, unit_price: string, tax_rate: string, discount_percentage: string, line_total: string}>, subtotal: string, discount_total: string, total_net: string, total_tax: string, total_gross: string}
     */
    public function calculateDecimal(array $items): array
    {
        $subtotal = '0';
        $net = '0';
        $vatEntries = [];
        $lines = [];

        foreach ($items as $index => $item) {
            $quantity = self::decimal($item['quantity'] ?? '1');
            $unitPrice = self::decimal($item['unit_price'] ?? '0');
            $discount = self::decimal($item['discount_percentage'] ?? '0');
            $taxRate = self::decimal($item['tax_rate'] ?? '0');
            $lineNet = self::lineNet($quantity, $unitPrice, $discount);

            $subtotal = Decimal::add($subtotal, Decimal::round2(Decimal::mul($quantity, $unitPrice)));
            $net = Decimal::add($net, $lineNet);
            $vatEntries[] = ['rate' => $taxRate, 'taxable' => $lineNet];

            $lines[$index] = [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'discount_percentage' => $discount,
                'line_total' => $lineNet,
            ];
        }

        $tax = Decimal::round2($this->vat->totalTax($vatEntries));
        $net = Decimal::round2($net);
        $subtotal = Decimal::round2($subtotal);

        return [
            'items' => $lines,
            'subtotal' => $subtotal,
            'discount_total' => Decimal::round2(Decimal::sub($subtotal, $net)),
            'total_net' => $net,
            'total_tax' => $tax,
            'total_gross' => Decimal::round2(Decimal::add($net, $tax)),
        ];
    }

    /**
     * The net of a line: quantity x unit price less the discount percentage, rounded to the cent.
     */
    public static function lineNet(mixed $quantity, mixed $unitPrice, mixed $discountPercentage = '0'): string
    {
        $gross = Decimal::mul(self::decimal($quantity), self::decimal($unitPrice));
        $factor = Decimal::sub('1', Decimal::div(self::decimal($discountPercentage), '100'));

        return Decimal::round2(Decimal::mul($gross, $factor));
    }

    /**
     * A decimal string from a stored or submitted value: strings and integers as they are, floats with ten decimals
     * (the shortest exact form of what was typed), null as zero.
     */
    public static function decimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return Decimal::normalize(rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.'));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return Decimal::normalize(is_int($value) ? $value : trim((string) $value));
    }
}
