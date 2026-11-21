<?php

declare(strict_types=1);

namespace Modules\Quoting\Domain\Services;

use Modules\Quoting\Domain\Enums\LineSelectionMode;
use Modules\Quoting\Domain\Enums\QuoteLineType;

/**
 * Prices a quote from plain arrays (so it prices a draft, a frozen snapshot and a customer's live selection the same way).
 *
 * Order of the cascade, per included line: tier price for the quantity -> line percentage then fixed discount -> section
 * percentage -> header percentage; each line net is rounded to 2 decimals, the tax is computed per rate on the sum of the
 * nets, and the Swiss total can be rounded to 0.05 (rounding adjustment reported apart). All arithmetic is bcmath on strings.
 *
 * Line keys: id, section_id, line_type, selection_mode, alternative_group, is_default_selected, quantity, unit_price,
 * unit_cost, discount_percent, discount_fixed, tier_prices (list of {min_qty, unit_price}), tax_rate.
 * Section keys: id, discount_percent.
 *
 * @phpstan-type Totals array{net: string, tax: string, gross: string, rounding: string, payable: string, cost: string, margin: string, margin_percent: string|null}
 */
final class QuotePricingCalculator
{
    private const int Scale = 8;

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $lines  In display order
     * @param  array<string, bool>  $selections  Customer choices by line id: an optional line on/off, the chosen line of an alternative group on
     * @param  string  $roundingStep  '0.05' for Swiss cash rounding of the payable total, '0.00' for none
     * @return array{lines: array<string, array<string, mixed>>, sections: array<string, array<string, string>>, tax_breakdown: list<array{rate: string, net: string, tax: string}>, totals: Totals}
     */
    public function calculate(array $sections, array $lines, array $selections = [], string $headerDiscountPercent = '0', string $roundingStep = '0.00'): array
    {
        $included = $this->resolveIncluded($lines, $selections);
        $sectionDiscount = [];
        foreach ($sections as $section) {
            $sectionDiscount[(string) $section['id']] = $this->dec($section['discount_percent'] ?? 0);
        }

        $lineResults = [];
        $sectionTotals = [];
        $running = [];
        $netByRate = [];
        $cost = '0';

        foreach ($lines as $line) {
            $id = (string) $line['id'];
            $sectionId = (string) ($line['section_id'] ?? '');
            $type = QuoteLineType::from((string) $line['line_type']);

            if ($type === QuoteLineType::Subtotal) {
                $lineResults[$id] = ['included' => true, 'net' => '0.00', 'running_subtotal' => $this->round($running[$sectionId] ?? '0', 2)];

                continue;
            }

            $isIncluded = $type->isPriced() && ($included[$id] ?? false);
            if (! $isIncluded) {
                $lineResults[$id] = ['included' => $type->isPriced() ? false : true, 'net' => '0.00', 'list_total' => '0.00', 'discount' => '0.00'];

                continue;
            }

            $quantity = $this->dec($line['quantity'] ?? 1);
            $unit = $this->unitPrice($line, $quantity);
            $listTotal = bcmul($quantity, $unit, self::Scale);
            $lineDiscount = bcadd(bcdiv(bcmul($listTotal, $this->dec($line['discount_percent'] ?? 0), self::Scale), '100', self::Scale), $this->dec($line['discount_fixed'] ?? 0), self::Scale);
            $afterLine = $this->floorZero(bcsub($listTotal, $lineDiscount, self::Scale));
            $afterSection = $this->applyPercent($afterLine, $sectionDiscount[$sectionId] ?? '0');
            $afterHeader = $this->applyPercent($afterSection, $this->dec($headerDiscountPercent));
            $net = $this->round($afterHeader, 2);
            $rate = $this->round($this->dec($line['tax_rate'] ?? 0), 4);

            $lineResults[$id] = [
                'included' => true,
                'unit_price' => $this->round($unit, 4),
                'list_total' => $this->round($listTotal, 2),
                'discount' => $this->round(bcsub($listTotal, $afterHeader, self::Scale), 2),
                'net' => $net,
                'tax_rate' => $rate,
            ];
            $netByRate[$rate] = bcadd($netByRate[$rate] ?? '0', $net, 2);
            $sectionTotals[$sectionId] = bcadd($sectionTotals[$sectionId] ?? '0', $net, 2);
            $running[$sectionId] = bcadd($running[$sectionId] ?? '0', $net, 2);
            $cost = bcadd($cost, bcmul($quantity, $this->dec($line['unit_cost'] ?? 0), self::Scale), self::Scale);
        }

        ksort($netByRate, SORT_NUMERIC);
        $breakdown = [];
        $totalNet = '0.00';
        $totalTax = '0.00';
        foreach ($netByRate as $rate => $net) {
            $tax = $this->round(bcdiv(bcmul($net, (string) $rate, self::Scale), '100', self::Scale), 2);
            $breakdown[] = ['rate' => (string) $rate, 'net' => $net, 'tax' => $tax];
            $totalNet = bcadd($totalNet, $net, 2);
            $totalTax = bcadd($totalTax, $tax, 2);
        }

        $gross = bcadd($totalNet, $totalTax, 2);
        $payable = $this->roundToStep($gross, $roundingStep);
        $cost = $this->round($cost, 2);
        $margin = bcsub($totalNet, $cost, 2);

        foreach (array_keys($sectionDiscount + $sectionTotals) as $sectionId) {
            $sectionTotals[$sectionId] = $this->round($sectionTotals[$sectionId] ?? '0', 2);
        }

        return [
            'lines' => $lineResults,
            'sections' => array_map(static fn (string $subtotal): array => ['subtotal' => $subtotal], $sectionTotals),
            'tax_breakdown' => $breakdown,
            'totals' => [
                'net' => $totalNet,
                'tax' => $totalTax,
                'gross' => $gross,
                'rounding' => bcsub($payable, $gross, 2),
                'payable' => $payable,
                'cost' => $cost,
                'margin' => $margin,
                'margin_percent' => bccomp($totalNet, '0', 2) === 0 ? null : $this->round(bcmul(bcdiv($margin, $totalNet, self::Scale), '100', self::Scale), 2),
            ],
        ];
    }

    /**
     * Which lines count: mandatory always; an optional one by the customer's toggle (default flag otherwise); in an
     * alternative group exactly one - the chosen line, else the default-flagged one, else the first of the group.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, bool>  $selections
     * @return array<string, bool>
     */
    public function resolveIncluded(array $lines, array $selections): array
    {
        $included = [];
        $groups = [];

        foreach ($lines as $line) {
            $id = (string) $line['id'];
            $mode = LineSelectionMode::from((string) ($line['selection_mode'] ?? 'mandatory'));

            if ($mode === LineSelectionMode::Mandatory) {
                $included[$id] = true;
            } elseif ($mode === LineSelectionMode::Optional) {
                $included[$id] = (bool) ($selections[$id] ?? $line['is_default_selected'] ?? false);
            } else {
                $groups[(string) ($line['alternative_group'] ?? 'default')][] = $line;
            }
        }

        foreach ($groups as $group) {
            $chosen = null;
            foreach ($group as $candidate) {
                if (($selections[(string) $candidate['id']] ?? false) === true) {
                    $chosen = (string) $candidate['id'];
                    break;
                }
            }
            if ($chosen === null) {
                foreach ($group as $candidate) {
                    if ((bool) ($candidate['is_default_selected'] ?? false)) {
                        $chosen = (string) $candidate['id'];
                        break;
                    }
                }
            }
            $chosen ??= (string) $group[0]['id'];

            foreach ($group as $candidate) {
                $included[(string) $candidate['id']] = (string) $candidate['id'] === $chosen;
            }
        }

        return $included;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function unitPrice(array $line, string $quantity): string
    {
        $unit = $this->dec($line['unit_price'] ?? 0);
        $bestMin = null;

        foreach ((array) ($line['tier_prices'] ?? []) as $tier) {
            $min = $this->dec($tier['min_qty'] ?? 0);
            if (bccomp($quantity, $min, 4) >= 0 && ($bestMin === null || bccomp($min, $bestMin, 4) > 0)) {
                $bestMin = $min;
                $unit = $this->dec($tier['unit_price'] ?? 0);
            }
        }

        return $unit;
    }

    private function applyPercent(string $amount, string $percent): string
    {
        if (bccomp($percent, '0', self::Scale) === 0) {
            return $amount;
        }

        return bcmul($amount, bcsub('1', bcdiv($percent, '100', self::Scale), self::Scale), self::Scale);
    }

    private function floorZero(string $value): string
    {
        return bccomp($value, '0', self::Scale) < 0 ? '0' : $value;
    }

    private function roundToStep(string $amount, string $step): string
    {
        if (bccomp($step, '0', 2) <= 0) {
            return $amount;
        }

        $steps = $this->round(bcdiv($amount, $step, self::Scale), 0);

        return $this->round(bcmul($steps, $step, self::Scale), 2);
    }

    /** Half-up rounding of a bcmath string (away from zero for negatives). */
    private function round(string $value, int $decimals): string
    {
        $half = '0.'.str_repeat('0', $decimals).'5';
        $adjusted = bccomp($value, '0', self::Scale) >= 0 ? bcadd($value, $half, $decimals + 1) : bcsub($value, $half, $decimals + 1);

        return bcadd($adjusted, '0', $decimals);
    }

    private function dec(mixed $value): string
    {
        $string = is_string($value) ? $value : (string) $value;

        return is_numeric($string) ? bcadd($string, '0', self::Scale) : '0';
    }
}
