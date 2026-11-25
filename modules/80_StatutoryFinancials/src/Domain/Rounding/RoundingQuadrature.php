<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Rounding;

use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Support\Money;

/**
 * The rounding quadrature: every leaf is rounded to the scale of the rounding mode and the residuals are posted to two
 * dedicated lines, so that every subtotal and total reconciles to the last unit.
 *
 *  - Income statement: the net result is the exact result rounded once. The difference to the sum of the rounded leaves goes to
 *    "Arrotondamenti attivi" (income) or "Arrotondamenti passivi" (cost).
 *  - Balance sheet: the result line takes the rounded result of the income statement; the difference between the rounded assets
 *    and the rounded liabilities and equity goes to the rounding reserve ("Riserva da arrotondamento").
 *
 * @phpstan-type Codes array{bs_profit: string, bs_reserve: string, bs_assets: string, bs_liabilities: string, is_profit: string, is_round_income: string, is_round_cost: string}
 */
final class RoundingQuadrature
{
    public function __construct(private readonly int $scale) {}

    /**
     * @param  array<string, string>  $bsLeaves  Exact amounts of the balance sheet leaves.
     * @param  array<string, string>  $isLeaves  Exact amounts of the income statement leaves.
     * @param  Codes  $codes
     */
    public function apply(LineCatalog $balanceSheet, LineCatalog $incomeStatement, array $bsLeaves, array $isLeaves, array $codes): QuadratureResult
    {
        // Income statement.
        $exactIs = $incomeStatement->totals($isLeaves);
        $exactProfit = $exactIs[$codes['is_profit']] ?? '0';
        $roundedProfit = Money::round($exactProfit, $this->scale);

        $isRounded = [];

        foreach ($incomeStatement->leaves() as $code) {
            $isRounded[$code] = Money::round($isLeaves[$code] ?? '0', $this->scale);
        }

        $isRounded[$codes['is_round_income']] = Money::round('0', $this->scale);
        $isRounded[$codes['is_round_cost']] = Money::round('0', $this->scale);

        $leafProfit = $incomeStatement->totals($isRounded)[$codes['is_profit']] ?? '0';
        $isResidual = Money::sub($roundedProfit, $leafProfit);

        if (Money::isPositive($isResidual)) {
            $isRounded[$codes['is_round_income']] = Money::round($isResidual, $this->scale);
        } elseif (Money::isNegative($isResidual)) {
            $isRounded[$codes['is_round_cost']] = Money::round(Money::neg($isResidual), $this->scale);
        }

        // Balance sheet.
        $bsRounded = [];

        foreach ($balanceSheet->leaves() as $code) {
            $bsRounded[$code] = Money::round($bsLeaves[$code] ?? '0', $this->scale);
        }

        $bsRounded[$codes['bs_profit']] = $roundedProfit;
        $bsRounded[$codes['bs_reserve']] = Money::round('0', $this->scale);

        $bsTotals = $balanceSheet->totals($bsRounded);
        $bsResidual = Money::sub($bsTotals[$codes['bs_assets']] ?? '0', $bsTotals[$codes['bs_liabilities']] ?? '0');
        $bsRounded[$codes['bs_reserve']] = Money::round($bsResidual, $this->scale);

        return new QuadratureResult(
            balanceSheetLeaves: $bsRounded,
            incomeStatementLeaves: $isRounded,
            balanceSheetTotals: $balanceSheet->totals($bsRounded),
            incomeStatementTotals: $incomeStatement->totals($isRounded),
            incomeStatementResidual: Money::round($isResidual, $this->scale),
            balanceSheetResidual: Money::round($bsResidual, $this->scale),
        );
    }

    /**
     * Rounds the leaves of a cash flow statement and posts the residual against the movement of cash to the rounding line.
     *
     * @param  array<string, string>  $leaves  Exact amounts of the cash flow leaves (without the rounding line).
     * @param  array{opening: string, closing: string, net_total: string, rounding: string, opening_line: string}  $codes
     * @return array{leaves: array<string, string>, totals: array<string, string>, residual: string}
     */
    public function applyCashFlow(LineCatalog $cashFlow, array $leaves, string $openingCash, string $closingCash, array $codes): array
    {
        $rounded = [];

        foreach ($cashFlow->leaves() as $code) {
            $rounded[$code] = Money::round($leaves[$code] ?? '0', $this->scale);
        }

        $rounded[$codes['opening_line']] = Money::round($openingCash, $this->scale);
        $rounded[$codes['rounding']] = Money::round('0', $this->scale);

        $net = $cashFlow->totals($rounded)[$codes['net_total']] ?? '0';
        $wanted = Money::sub(Money::round($closingCash, $this->scale), Money::round($openingCash, $this->scale));
        $residual = Money::sub($wanted, $net);
        $rounded[$codes['rounding']] = Money::round($residual, $this->scale);

        return ['leaves' => $rounded, 'totals' => $cashFlow->totals($rounded), 'residual' => Money::round($residual, $this->scale)];
    }
}
