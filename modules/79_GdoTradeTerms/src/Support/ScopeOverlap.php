<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Support;

use Illuminate\Support\Carbon;

/**
 * Whether two rebate scales or two promotions of a contract can count the same sale: their windows overlap and, in every
 * dimension (product, category, brand, delivery point), either one of them is unrestricted or they share an id.
 */
final class ScopeOverlap
{
    private const array Dimensions = ['product_ids', 'category_ids', 'brand_ids', 'delivery_point_ids'];

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public static function overlaps(Carbon $leftStart, Carbon $leftEnd, array $left, Carbon $rightStart, Carbon $rightEnd, array $right): bool
    {
        if ($leftStart->gt($rightEnd) || $rightStart->gt($leftEnd)) {
            return false;
        }

        foreach (self::Dimensions as $dimension) {
            $a = self::ids($left[$dimension] ?? null);
            $b = self::ids($right[$dimension] ?? null);

            if ($a !== [] && $b !== [] && array_intersect($a, $b) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_map('strval', $value))) : [];
    }
}
