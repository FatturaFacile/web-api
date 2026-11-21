<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Modules\AnalyticsDss\Domain\Services\CashConversionCycleCalculator;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;

/**
 * Core executive figures of a period (P&L, margins, cash and working capital) shared by every DSS query handler.
 */
final class ExecutiveFigures
{
    public function __construct(private readonly MartReader $reader, private readonly CashConversionCycleCalculator $ccc) {}

    /**
     * @return array<string, mixed>
     */
    public function period(DssFilter $filter, string $from, string $to): array
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $movements = $this->reader->movements($filter, $from, $to);
        $balances = $this->reader->balances($filter, $to);

        return [
            ...$this->derived($movements, $from, $to, $days),
            'balances' => $balances,
            'working_capital' => $this->ccc->calculate(
                receivables: $balances['receivable'], payables: $balances['payable'], inventory: $balances['inventory'], revenue: $movements['revenue'], cogs: $movements['cogs'],
                currentAssets: $balances['cash'] + $balances['receivable'] + $balances['inventory'], currentLiabilities: $balances['payable'],
                operatingCashFlow: $movements['cash'], capex: 0.0, cashBalance: $balances['cash'], netCashFlow: $movements['cash'], days: $days,
            ),
        ];
    }

    /**
     * Budget of the period in the same shape (no balance sheet figures: the budget is a P&L plan).
     *
     * @return array<string, mixed>
     */
    public function budget(DssFilter $filter): array
    {
        $budget = $this->reader->budget($filter, $filter->from, $filter->to);

        return [
            ...$this->derived($budget + ['cash' => 0.0], $filter->from, $filter->to, $filter->days()),
            'balances' => null, 'working_capital' => null, 'budget_found' => $budget['found'],
        ];
    }

    /**
     * @param  array<string, float|bool>  $movements
     * @return array<string, mixed>
     */
    private function derived(array $movements, string $from, string $to, int $days): array
    {
        $revenue = (float) $movements['revenue'];
        $grossMargin = $revenue - (float) $movements['cogs'];
        $ebitda = $grossMargin - (float) $movements['opex'];

        return [
            'from' => $from, 'to' => $to, 'days' => $days,
            'revenue' => $revenue, 'cogs' => (float) $movements['cogs'], 'opex' => (float) $movements['opex'], 'depreciation' => (float) $movements['depreciation'],
            'gross_margin' => round($grossMargin, 4), 'gross_margin_pct' => $revenue != 0.0 ? round($grossMargin / $revenue * 100.0, 2) : 0.0,
            'ebitda' => round($ebitda, 4), 'ebitda_margin_pct' => $revenue != 0.0 ? round($ebitda / $revenue * 100.0, 2) : 0.0,
            'net_cash_flow' => (float) ($movements['cash'] ?? 0.0),
        ];
    }
}
