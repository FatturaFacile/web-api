<?php

declare(strict_types=1);

namespace Modules\DownPayments\Services;

use InvalidArgumentException;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Splits an amount over the VAT rates of a document in proportion to their weight, to the exact cent.
 *
 * The Largest Remainder (Hamilton) method gives every rate the whole cents of its quota and hands the cents left over to the rates
 * with the biggest fractional remainders, so the slices always add up to the requested amount with no rounding drift. The VAT of a
 * slice is the VAT of its net at its rate, rounded to the cent, which is what the invoice computes for the same lines.
 *
 * @phpstan-type Slice array{vat_rate: string, net: string, vat: string, gross: string}
 */
final class MultiVatProportionalAllocator
{
    /**
     * Splits a net amount: the nets add up to $net exactly, each rate adds its own VAT on top.
     *
     * @param  array<string, string>  $bases  net of the source per VAT rate (percentage => net)
     * @return list<Slice>
     */
    public function allocateNet(string $net, array $bases): array
    {
        $weights = $this->weights($bases, false);
        $slices = [];

        foreach ($this->hamilton($this->toCents($net), $weights) as $rate => $cents) {
            if ($cents === 0) {
                continue;
            }

            $slices[] = $this->slice((string) $rate, $this->fromCents($cents));
        }

        return $slices;
    }

    /**
     * Splits a gross amount: the grosses (net plus VAT of each rate) add up to $gross whenever the cents are attainable at every
     * rate. A gross a rate cannot reach (22 % goes 100.00, 100.02, ...) takes the nearest net the rate can reach, and
     * `drift()` reports what is left.
     *
     * @param  array<string, string>  $bases
     * @return list<Slice>
     */
    public function allocateGross(string $gross, array $bases): array
    {
        $weights = $this->weights($bases, true);
        $slices = [];

        foreach ($this->hamilton($this->toCents($gross), $weights) as $rate => $cents) {
            if ($cents === 0) {
                continue;
            }

            $target = $this->fromCents($cents);
            $net = Decimal::round2(Decimal::div($target, Decimal::add('1', Decimal::div((string) $rate, '100'))));
            $best = $this->slice((string) $rate, $net);

            foreach (['-0.02', '-0.01', '0.01', '0.02'] as $step) {
                $candidate = $this->slice((string) $rate, Decimal::add($net, $step));

                if (abs((float) Decimal::sub($candidate['gross'], $target)) < abs((float) Decimal::sub($best['gross'], $target))) {
                    $best = $candidate;
                }
            }

            $slices[] = $best;
        }

        return $slices;
    }

    /**
     * The difference between a requested gross and the grosses of its slices (zero when the split is exact).
     *
     * @param  list<Slice>  $slices
     */
    public function drift(string $requestedGross, array $slices): string
    {
        return Decimal::round2(Decimal::sub(Decimal::round2($requestedGross), $this->sum($slices, 'gross')));
    }

    /**
     * @param  list<Slice>  $slices
     */
    public function sum(array $slices, string $part): string
    {
        return Decimal::round2(array_reduce($slices, static fn (string $sum, array $slice): string => Decimal::add($sum, $slice[$part]), '0'));
    }

    /**
     * @return Slice
     */
    public function slice(string $rate, string $net): array
    {
        $net = Decimal::round2($net);
        $vat = Decimal::round2(Decimal::div(Decimal::mul($net, $rate), '100'));

        return ['vat_rate' => $rate, 'net' => $net, 'vat' => $vat, 'gross' => Decimal::round2(Decimal::add($net, $vat))];
    }

    /**
     * @param  array<string, string>  $bases
     * @return array<string, string>
     */
    private function weights(array $bases, bool $gross): array
    {
        $weights = [];

        foreach ($bases as $rate => $base) {
            $rate = Decimal::round2((string) $rate);
            $weight = $gross ? Decimal::mul($base, Decimal::add('1', Decimal::div($rate, '100'))) : $base;

            if (Decimal::isPositive($weight)) {
                $weights[$rate] = Decimal::add($weights[$rate] ?? '0', $weight);
            }
        }

        if ($weights === []) {
            throw new InvalidArgumentException('The source has no positive VAT base to split an amount over.');
        }

        ksort($weights, SORT_NUMERIC);

        return $weights;
    }

    /**
     * Largest Remainder method over integer cents.
     *
     * @param  array<string, string>  $weights
     * @return array<string, int>
     */
    private function hamilton(int $totalCents, array $weights): array
    {
        $sum = array_reduce($weights, static fn (string $carry, string $weight): string => Decimal::add($carry, $weight), '0');
        $whole = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $rate => $weight) {
            $quota = bcdiv(bcmul((string) $totalCents, $weight, 12), $sum, 12);
            $floor = (int) bcadd($quota, '0', 0);
            $whole[$rate] = $floor;
            $remainders[$rate] = bcsub($quota, (string) $floor, 12);
            $allocated += $floor;
        }

        $order = array_keys($weights);
        usort($order, static function (string|int $a, string|int $b) use ($remainders, $weights): int {
            return [bccomp($remainders[$b], $remainders[$a], 12), bccomp($weights[$b], $weights[$a], 2), (float) $a <=> (float) $b] <=> [0, 0, 0];
        });

        for ($left = $totalCents - $allocated, $i = 0; $left > 0; $left--, $i++) {
            $whole[$order[$i % count($order)]]++;
        }

        return $whole;
    }

    private function toCents(string $amount): int
    {
        return (int) bcmul(Decimal::round2($amount), '100', 0);
    }

    private function fromCents(int $cents): string
    {
        return bcdiv((string) $cents, '100', 2);
    }
}
