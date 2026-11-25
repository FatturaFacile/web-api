<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Services;

use Carbon\CarbonImmutable;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;

/**
 * Month period codes (YYYY-MM) used by sell-out, billing and benchmarks.
 */
final class BillingPeriod
{
    public static function assert(string $period): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new FranchiseNetworkException('period_invalid');
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function bounds(string $period): array
    {
        self::assert($period);
        $start = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    public static function previous(string $period): string
    {
        return self::bounds($period)[0]->subMonthNoOverflow()->format('Y-m');
    }

    public static function sameMonthLastYear(string $period): string
    {
        return self::bounds($period)[0]->subYear()->format('Y-m');
    }
}
