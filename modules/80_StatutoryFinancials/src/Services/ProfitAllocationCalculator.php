<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Strategy\ProfitAllocationInput;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Proposal for the allocation of the profit: the legal reserve from profit (5% of the profit until it reaches 20% of the share
 * capital: Art. 2430 c.c. in Italy, Art. 671 CO in Switzerland), the dividends and the other reserves proposed, and the amount
 * carried forward. A loss is carried forward unchanged and no reserve is allocated.
 */
final class ProfitAllocationCalculator
{
    private const string Rate = '0.05';

    private const string Cap = '0.20';

    /**
     * @return array<string, mixed>
     */
    public function calculate(ProfitAllocationInput $input, string $article): array
    {
        $profit = Money::round($input->netProfit);
        $balanceSheetProfit = Money::add($profit, $input->carriedForward);
        $cap = Money::mul($input->shareCapital, self::Cap);
        $room = Money::isPositive(Money::sub($cap, $input->legalReserve)) ? Money::sub($cap, $input->legalReserve) : '0';
        $legalReserve = '0';
        $warnings = [];

        if (Money::isPositive($profit)) {
            $legalReserve = Money::min(Money::mul($profit, self::Rate), $room);
        }

        $distributable = Money::isPositive($balanceSheetProfit) ? Money::sub($balanceSheetProfit, $legalReserve) : '0';
        $dividends = Money::isPositive($input->dividends) ? Money::round($input->dividends) : '0';
        $otherReserves = Money::isPositive($input->otherReserves) ? Money::round($input->otherReserves) : '0';

        if (Money::compare(Money::add($dividends, $otherReserves), $distributable) > 0) {
            $warnings[] = 'allocation_exceeds_distributable';
        }

        $carriedForward = Money::sub(Money::sub(Money::sub($balanceSheetProfit, $legalReserve), $dividends), $otherReserves);

        return [
            'article' => $article,
            'net_profit' => $profit,
            'carried_forward_in' => Money::round($input->carriedForward),
            'balance_sheet_profit' => $balanceSheetProfit,
            'share_capital' => Money::round($input->shareCapital),
            'legal_reserve_cap' => Money::round($cap),
            'legal_reserve_existing' => Money::round($input->legalReserve),
            'legal_reserve_room' => Money::round($room),
            'legal_reserve_allocation' => Money::round($legalReserve),
            'legal_reserve_cap_reached' => ! Money::isPositive($room),
            'dividends' => $dividends,
            'other_reserves' => $otherReserves,
            'carried_forward_out' => Money::round($carriedForward),
            'warnings' => $warnings,
        ];
    }
}
