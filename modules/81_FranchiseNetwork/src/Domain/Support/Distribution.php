<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

/**
 * Average, median and 75th percentile (top quartile threshold) of a sample, by linear interpolation between closest ranks.
 * Only these aggregates ever leave the benchmark: no individual value of a peer store is exposed.
 */
final class Distribution
{
    /**
     * @param  list<string|int|float>  $values
     * @return array{average: string|null, median: string|null, p75: string|null, sample_size: int}
     */
    public static function summarize(array $values): array
    {
        $count = count($values);

        if ($count === 0) {
            return ['average' => null, 'median' => null, 'p75' => null, 'sample_size' => 0];
        }

        $sorted = array_map(Dec::of(...), $values);
        usort($sorted, Dec::cmp(...));
        $sum = '0';

        foreach ($sorted as $value) {
            $sum = Dec::add($sum, $value);
        }

        return [
            'average' => bcadd(Dec::div($sum, $count), '0', 4),
            'median' => self::percentile($sorted, 50),
            'p75' => self::percentile($sorted, 75),
            'sample_size' => $count,
        ];
    }

    /**
     * @param  list<string>  $sorted  ascending
     */
    public static function percentile(array $sorted, int $percent): string
    {
        $count = count($sorted);

        if ($count === 1) {
            return bcadd($sorted[0], '0', 4);
        }

        $rank = Dec::mul(Dec::div($percent, 100), $count - 1);
        $lower = (int) bcadd($rank, '0', 0);
        $fraction = Dec::sub($rank, $lower);
        $upper = min($lower + 1, $count - 1);

        return bcadd(Dec::add($sorted[$lower], Dec::mul(Dec::sub($sorted[$upper], $sorted[$lower]), $fraction)), '0', 4);
    }
}
