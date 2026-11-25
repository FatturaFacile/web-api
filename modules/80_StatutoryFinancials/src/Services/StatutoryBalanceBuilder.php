<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Illuminate\Support\Collection;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Rounding\RoundingQuadrature;
use Modules\StatutoryFinancials\Domain\Statement\ClassificationResult;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Builds the statutory balance sheet and income statement of a dossier from the post-adjustment ledger: aggregates the balances of
 * the fiscal year (and of the comparative year), reclassifies the chart of accounts into the official lines, places bifronte accounts
 * on the asset or the liability side by the sign of their balance and applies the rounding quadrature.
 */
final class StatutoryBalanceBuilder
{
    public function __construct(
        private readonly LedgerSnapshotService $snapshots,
        private readonly AccountClassifier $classifier,
        private readonly LineCatalogRepository $catalogs,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly CostOfSalesConverter $costOfSales,
    ) {}

    public function build(StatutoryDossier $dossier): StatementBundle
    {
        $standard = $dossier->accounting_standard;
        $strategy = $this->strategies->for($standard);
        $method = in_array($dossier->income_statement_method, $strategy->incomeStatementMethods($standard), true) ? $dossier->income_statement_method : IncomeStatementMethod::TotalCost;
        $codes = $strategy->roundingCodes($standard, $method);
        $options = $dossier->options ?? [];
        $allocation = is_array($options['cogs_allocation'] ?? null) ? $options['cogs_allocation'] : [];

        $balanceSheet = $this->catalogs->catalog($standard, 'balance_sheet');
        $incomeStatement = $this->catalogs->catalog($standard, 'income_statement', $method->value);

        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $tenantId = (string) $dossier->tenant_id;

        $snapshot = $this->snapshots->forYear($tenantId, $year);
        [$classification, $bsExact, $isExact] = $this->exact($standard, $method, $snapshot, $codes, $balanceSheet, $incomeStatement, $allocation);
        $quadrature = (new RoundingQuadrature($dossier->rounding_to_unit_mode->scale()))->apply($balanceSheet, $incomeStatement, $bsExact, $isExact, $codes);

        $warnings = [];

        foreach ($classification->unmapped as $unmapped) {
            $warnings[] = ['code' => 'validation.unmapped_account', 'severity' => 'warning', 'params' => ['account' => $unmapped['account_code'], 'line' => $unmapped['line']]];
        }

        foreach (['balance_sheet' => $quadrature->balanceSheetResidual, 'income_statement' => $quadrature->incomeStatementResidual] as $statement => $residual) {
            if (! Money::isZero($residual)) {
                $warnings[] = ['code' => 'validation.rounding_residual', 'severity' => 'info', 'params' => ['statement' => $statement, 'residual' => $residual]];
            }
        }

        $comparativeBs = null;
        $comparativeIs = null;

        if ($dossier->comparative_prior_year_id !== null) {
            $prior = FiscalYear::query()->find($dossier->comparative_prior_year_id);

            if ($prior !== null) {
                [, $priorBs, $priorIs] = $this->exact($standard, $method, $this->snapshots->forYear($tenantId, $prior), $codes, $balanceSheet, $incomeStatement, $allocation);
                $priorQuadrature = (new RoundingQuadrature($dossier->rounding_to_unit_mode->scale()))->apply($balanceSheet, $incomeStatement, $priorBs, $priorIs, $codes);
                $comparativeBs = $priorQuadrature->balanceSheetTotals;
                $comparativeIs = $priorQuadrature->incomeStatementTotals;
            }
        } else {
            $warnings[] = ['code' => 'validation.comparative_missing', 'severity' => 'info', 'params' => []];
        }

        [$openingBs, $openingProfit] = $this->opening($tenantId, $year, $dossier, $standard, $method, $codes, $balanceSheet, $incomeStatement, $allocation);

        return new StatementBundle(
            standard: $standard,
            method: $method,
            scale: $dossier->rounding_to_unit_mode->scale(),
            balanceSheet: $balanceSheet,
            incomeStatement: $incomeStatement,
            balanceSheetExact: $bsExact,
            incomeStatementExact: $isExact,
            quadrature: $quadrature,
            comparativeBalanceSheet: $comparativeBs,
            comparativeIncomeStatement: $comparativeIs,
            classification: $classification,
            openingBalanceSheetExact: $openingBs,
            openingProfit: $openingProfit,
            codes: $codes,
            warnings: $warnings,
        );
    }

    /**
     * @param  array{balance: Collection<string, object>, income: Collection<string, object>}  $snapshot
     * @param  array{bs_profit: string, bs_reserve: string, bs_assets: string, bs_liabilities: string, is_profit: string, is_round_income: string, is_round_cost: string}  $codes
     * @param  array<string, string>  $allocation
     * @return array{0: ClassificationResult, 1: array<string, string>, 2: array<string, string>}
     */
    private function exact(AccountingStandard $standard, IncomeStatementMethod $method, array $snapshot, array $codes, LineCatalog $balanceSheet, LineCatalog $incomeStatement, array $allocation): array
    {
        $classification = $this->classifier->classify($standard, $snapshot['balance'], $snapshot['income']);
        $incomeLeaves = $method === IncomeStatementMethod::CostOfSales
            ? $this->costOfSales->convert($classification->incomeLeaves, $allocation)
            : $classification->incomeLeaves;
        $profit = $incomeStatement->totals($incomeLeaves)[$codes['is_profit']] ?? '0';
        $balanceLeaves = $classification->balanceLeaves;
        $balanceLeaves[$codes['bs_profit']] = $profit;

        return [$classification, $balanceLeaves, $incomeLeaves];
    }

    /**
     * @param  array{bs_profit: string, bs_reserve: string, bs_assets: string, bs_liabilities: string, is_profit: string, is_round_income: string, is_round_cost: string}  $codes
     * @param  array<string, string>  $allocation
     * @return array{0: array<string, string>, 1: string}
     */
    private function opening(string $tenantId, FiscalYear $year, StatutoryDossier $dossier, AccountingStandard $standard, IncomeStatementMethod $method, array $codes, LineCatalog $balanceSheet, LineCatalog $incomeStatement, array $allocation): array
    {
        $prior = $dossier->comparative_prior_year_id !== null ? FiscalYear::query()->find($dossier->comparative_prior_year_id) : $this->snapshots->previousYear($year);
        [, $bsExact, $isExact] = $this->exact($standard, $method, $this->snapshots->opening($tenantId, $year, $prior), $codes, $balanceSheet, $incomeStatement, $allocation);

        return [$bsExact, $bsExact[$codes['bs_profit']] ?? '0'];
    }
}
