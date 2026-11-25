<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Strategy;

final readonly class ProfitAllocationInput
{
    /**
     * @param  string  $netProfit  Result of the year (negative for a loss).
     * @param  string  $carriedForward  Profit (positive) or loss (negative) carried forward from earlier years.
     * @param  string  $shareCapital  Paid-in share capital.
     * @param  string  $legalReserve  Legal reserve from profit already set up.
     * @param  string  $dividends  Dividends proposed.
     * @param  string  $otherReserves  Allocation proposed to voluntary or statutory reserves.
     */
    public function __construct(
        public string $netProfit,
        public string $carriedForward,
        public string $shareCapital,
        public string $legalReserve,
        public string $dividends = '0',
        public string $otherReserves = '0',
    ) {}
}
