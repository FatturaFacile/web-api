<?php

declare(strict_types=1);

namespace Modules\Forecasting\Domain\Entities;

use DateTimeImmutable;

/**
 * One liquidity projection: the daily buckets, the drivers behind them and the what-if overrides that produced them.
 *
 * A daily bucket is {date, inflows, outflows, statutory_outflows, balance}; inflows, outflows and balance each carry
 * p10 / p50 / p90, and statutory_outflows lists the tax and payroll events of the day.
 */
final readonly class AiLiquidityForecastRun
{
    /**
     * @param  list<array<string, mixed>>  $dailyProjections
     * @param  array<string, mixed>  $keyDrivers
     * @param  array<string, mixed>|null  $whatIfParameters
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public DateTimeImmutable $generatedAt,
        public int $horizonDays,
        public float $startingCashBalance,
        public array $dailyProjections,
        public array $keyDrivers,
        public ?array $whatIfParameters = null,
    ) {}

    /**
     * The lowest expected (p50) balance of the horizon and the day it is reached.
     *
     * @return array{date: string, balance: float}|null
     */
    public function cashTrough(): ?array
    {
        $trough = null;

        foreach ($this->dailyProjections as $bucket) {
            $balance = (float) $bucket['balance']['p50'];

            if ($trough === null || $balance < $trough['balance']) {
                $trough = ['date' => (string) $bucket['date'], 'balance' => $balance];
            }
        }

        return $trough;
    }
}
