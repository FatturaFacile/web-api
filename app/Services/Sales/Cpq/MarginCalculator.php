<?php

declare(strict_types=1);

namespace App\Services\Sales\Cpq;

/**
 * Industrial cost, net selling price and gross operating margin of quote lines and of the whole quote, in decimal
 * arithmetic.
 *
 * Per line: list total = quantity x unit price, net = list total less the line discount, cost = quantity x (unit cost +
 * labor cost) x cost exchange rate (a cost kept in another currency than the quote). At quote level the global discount
 * applies to the sum of the net lines, a surcharge and a one-off setup cost are added, and the margin percentage is
 * (net - cost) / net x 100. A net price of zero has no percentage (null) instead of a division by zero; a margin below
 * zero is reported as it is.
 */
final class MarginCalculator
{
    private const int Scale = 6;

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array{global_discount_percent?: mixed, surcharge_amount?: mixed, setup_cost?: mixed}  $header
     * @return array{lines: list<array<string, string|null>>, totals: array<string, string|null>}
     */
    public function calculate(array $lines, array $header = []): array
    {
        $results = [];
        $list = '0';
        $lineNet = '0';
        $cost = '0';

        foreach ($lines as $line) {
            $result = $this->line($line);
            $results[] = $result;
            $list = bcadd($list, (string) $result['list_total'], self::Scale);
            $lineNet = bcadd($lineNet, (string) $result['net_total'], self::Scale);
            $cost = bcadd($cost, (string) $result['cost_total'], self::Scale);
        }

        $globalPercent = $this->percent($header['global_discount_percent'] ?? '0');
        $globalDiscount = bcdiv(bcmul($lineNet, $globalPercent, self::Scale), '100', self::Scale);
        $surcharge = $this->num($header['surcharge_amount'] ?? '0');
        $setup = $this->num($header['setup_cost'] ?? '0');
        $net = bcadd(bcsub($lineNet, $globalDiscount, self::Scale), $surcharge, self::Scale);
        $totalCost = bcadd($cost, $setup, self::Scale);
        $margin = bcsub($net, $totalCost, self::Scale);

        return [
            'lines' => $results,
            'totals' => [
                'list_total' => $this->round2($list),
                'line_discount_total' => $this->round2(bcsub($list, $lineNet, self::Scale)),
                'global_discount_percent' => $this->round2($globalPercent),
                'global_discount_amount' => $this->round2($globalDiscount),
                'surcharge_amount' => $this->round2($surcharge),
                'net_total' => $this->round2($net),
                'cost_total' => $this->round2($totalCost),
                'margin_amount' => $this->round2($margin),
                'margin_percent' => $this->marginPercent($margin, $net),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, string|null>
     */
    public function line(array $line): array
    {
        $quantity = $this->num($line['quantity'] ?? '0');
        $unitPrice = $this->num($line['unit_price'] ?? '0');
        $discountPercent = $this->percent($line['discount_percentage'] ?? '0');
        $rate = $this->num($line['cost_exchange_rate'] ?? '1');
        $rate = bccomp($rate, '0', self::Scale) === 0 ? '1' : $rate;
        $unitCost = bcmul(bcadd($this->num($line['unit_cost'] ?? '0'), $this->num($line['labor_cost'] ?? '0'), self::Scale), $rate, self::Scale);

        $list = bcmul($quantity, $unitPrice, self::Scale);
        $net = bcsub($list, bcdiv(bcmul($list, $discountPercent, self::Scale), '100', self::Scale), self::Scale);
        $cost = bcmul($quantity, $unitCost, self::Scale);
        $margin = bcsub($net, $cost, self::Scale);

        return [
            'list_total' => $this->round2($list),
            'discount_percentage' => $this->round2($discountPercent),
            'net_total' => $this->round2($net),
            'cost_total' => $this->round2($cost),
            'margin_amount' => $this->round2($margin),
            'margin_percent' => $this->marginPercent($margin, $net),
        ];
    }

    /**
     * Margin as a percentage of the net price, or null when the net price is zero.
     */
    public function marginPercent(string $margin, string $net): ?string
    {
        if (bccomp($net, '0', self::Scale) === 0) {
            return null;
        }

        return $this->round2(bcdiv(bcmul($margin, '100', self::Scale), $net, self::Scale));
    }

    public function round2(string $value): string
    {
        return bcadd($value, bccomp($value, '0', self::Scale) >= 0 ? '0.005' : '-0.005', 2);
    }

    private function percent(mixed $value): string
    {
        $percent = $this->num($value);

        if (bccomp($percent, '0', self::Scale) < 0) {
            return '0';
        }

        return bccomp($percent, '100', self::Scale) > 0 ? '100' : $percent;
    }

    private function num(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $text = is_float($value) ? number_format($value, self::Scale, '.', '') : (string) $value;

        return is_numeric($text) ? $text : '0';
    }
}
