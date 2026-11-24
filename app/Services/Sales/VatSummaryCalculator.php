<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The VAT of a sales document, computed the way the FatturaPA summary (DatiRiepilogo) states it: the taxable amounts are
 * summed per rate and nature, and the tax of each group is that sum times the rate, rounded half up to two decimals.
 * Totals, ledger tax lines and the XML summary all use this calculator, so the stored total tax, the ledger and the
 * Imposta of the summary agree to the cent. A tax per line exists only for display, and the allocation below keeps those
 * line amounts summing to the tax of their group.
 */
final class VatSummaryCalculator
{
    /**
     * Groups the entries by rate and nature and computes the tax of each group.
     *
     * @param  iterable<int|string, array{rate: string|int|float|null, taxable: string|int|float|null, nature?: ?string}>  $entries
     * @return array<string, array{rate: string, nature: ?string, taxable: string, tax: string}>
     */
    public function summarize(iterable $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $rate = $this->rate($entry['rate']);
            $nature = $entry['nature'] ?? null;
            $key = $this->groupKey($rate, $nature);
            $current = $groups[$key]['taxable'] ?? '0';

            $groups[$key] = ['rate' => $rate, 'nature' => $nature, 'taxable' => Decimal::add($current, $this->amount($entry['taxable']))];
        }

        foreach ($groups as $key => $group) {
            $taxable = Decimal::round2($group['taxable']);
            $groups[$key] = ['rate' => $group['rate'], 'nature' => $group['nature'], 'taxable' => $taxable, 'tax' => $this->taxOn($taxable, $group['rate'])];
        }

        return $groups;
    }

    /**
     * The total tax of the document: the sum of the tax of each group.
     *
     * @param  iterable<int|string, array{rate: string|int|float|null, taxable: string|int|float|null, nature?: ?string}>  $entries
     */
    public function totalTax(iterable $entries): string
    {
        $total = '0';

        foreach ($this->summarize($entries) as $group) {
            $total = Decimal::add($total, $group['tax']);
        }

        return Decimal::round2($total);
    }

    /**
     * The tax of one summary group: taxable times rate, rounded half up to two decimals.
     */
    public function taxOn(string|int|float $taxable, string|int|float $rate): string
    {
        return Decimal::round2(Decimal::div(Decimal::mul($this->amount($taxable), $this->rate($rate)), '100'));
    }

    /**
     * Spreads the tax of each group over its entries (largest remainder), so the amounts per entry, keyed like the
     * entries, sum exactly to the tax of their group.
     *
     * @param  array<int|string, array{rate: string|int|float|null, taxable: string|int|float|null, nature?: ?string}>  $entries
     * @return array<int|string, string>
     */
    public function allocate(array $entries): array
    {
        $summary = $this->summarize($entries);
        $byGroup = [];

        foreach ($entries as $index => $entry) {
            $byGroup[$this->groupKey($this->rate($entry['rate']), $entry['nature'] ?? null)][] = $index;
        }

        $allocated = [];

        foreach ($byGroup as $key => $indexes) {
            $exact = [];
            $sum = '0';

            foreach ($indexes as $index) {
                $exact[$index] = Decimal::div(Decimal::mul($this->amount($entries[$index]['taxable']), $summary[$key]['rate']), '100');
                $allocated[$index] = Decimal::round2($exact[$index]);
                $sum = Decimal::add($sum, $allocated[$index]);
            }

            $difference = Decimal::sub($summary[$key]['tax'], $sum);

            if (Decimal::isZero($difference)) {
                continue;
            }

            $step = Decimal::isPositive($difference) ? '0.01' : '-0.01';
            $order = $indexes;
            usort($order, function (int|string $left, int|string $right) use ($exact, $allocated, $step): int {
                $leftRemainder = Decimal::sub($exact[$left], $allocated[$left]);
                $rightRemainder = Decimal::sub($exact[$right], $allocated[$right]);

                return Decimal::isPositive($step) ? Decimal::compare($rightRemainder, $leftRemainder) : Decimal::compare($leftRemainder, $rightRemainder);
            });

            $steps = (int) Decimal::div(Decimal::abs($difference), '0.01');

            for ($i = 0; $i < $steps; $i++) {
                $index = $order[$i % count($order)];
                $allocated[$index] = Decimal::round2(Decimal::add($allocated[$index], $step));
            }
        }

        $result = [];

        foreach (array_keys($entries) as $index) {
            $result[$index] = $allocated[$index];
        }

        return $result;
    }

    private function groupKey(string $rate, ?string $nature): string
    {
        return $rate.'|'.($nature ?? '');
    }

    /**
     * A rate as the summary states it, with two decimals.
     */
    private function rate(string|int|float|null $rate): string
    {
        return Decimal::round2($this->amount($rate));
    }

    private function amount(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_float($value)) {
            return number_format($value, 6, '.', '');
        }

        return Decimal::normalize($value);
    }
}
