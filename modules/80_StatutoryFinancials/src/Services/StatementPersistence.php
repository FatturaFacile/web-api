<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Rounding\QuadratureResult;
use Modules\StatutoryFinancials\Domain\Statement\ClassificationResult;
use Modules\StatutoryFinancials\Domain\Statement\LineCatalog;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Models\DossierLine;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Stores the generated statements on the dossier (structured JSON for the inspector and normalised line rows) and rebuilds the
 * statement bundle from them, so the notes, the XBRL export, the ratios and the PDF/A documents always work on the figures that
 * were generated and shown, not on a ledger that may have moved since.
 */
final class StatementPersistence
{
    public function __construct(private readonly LineCatalogRepository $catalogs) {}

    public function persist(StatutoryDossier $dossier, StatementBundle $bundle): void
    {
        $balanceSheetExact = $bundle->balanceSheet->totals($bundle->balanceSheetExact);
        $incomeStatementExact = $bundle->incomeStatement->totals($bundle->incomeStatementExact);

        $dossier->balance_sheet_lines = [
            'lines' => $this->lines($bundle->balanceSheet, $bundle->quadrature->balanceSheetTotals, $balanceSheetExact, $bundle->comparativeBalanceSheet),
            'total_assets' => $bundle->quadrature->balanceSheetTotals[$bundle->codes['bs_assets']] ?? '0',
            'total_liabilities_equity' => $bundle->quadrature->balanceSheetTotals[$bundle->codes['bs_liabilities']] ?? '0',
        ];
        $dossier->income_statement_lines = [
            'method' => $bundle->method->value,
            'lines' => $this->lines($bundle->incomeStatement, $bundle->quadrature->incomeStatementTotals, $incomeStatementExact, $bundle->comparativeIncomeStatement),
            'net_result' => $bundle->quadrature->incomeStatementTotals[$bundle->codes['is_profit']] ?? '0',
        ];
        $dossier->quadrature = [
            'scale' => $bundle->scale,
            'codes' => $bundle->codes,
            'balance_sheet_exact' => $bundle->balanceSheetExact,
            'income_statement_exact' => $bundle->incomeStatementExact,
            'opening_balance_sheet_exact' => $bundle->openingBalanceSheetExact,
            'opening_profit' => $bundle->openingProfit,
            'income_statement_residual' => $bundle->quadrature->incomeStatementResidual,
            'balance_sheet_residual' => $bundle->quadrature->balanceSheetResidual,
            'rounding_reserve' => $bundle->quadrature->balanceSheetLeaves[$bundle->codes['bs_reserve']] ?? '0',
            'rounding_income' => $bundle->quadrature->incomeStatementLeaves[$bundle->codes['is_round_income']] ?? '0',
            'rounding_cost' => $bundle->quadrature->incomeStatementLeaves[$bundle->codes['is_round_cost']] ?? '0',
        ];
        $dossier->reclassification_report = [
            'income_leaves' => $bundle->classification->incomeLeaves,
            'bifront' => $bundle->classification->bifront,
            'unmapped' => $bundle->classification->unmapped,
            'sources' => $bundle->classification->sources,
            'warnings' => $bundle->warnings,
        ];

        $dossier->save();

        DossierLine::query()->where('dossier_id', $dossier->id)->whereIn('statement', ['balance_sheet', 'income_statement'])->forceDelete();
        $this->rows($dossier, 'balance_sheet', $dossier->balance_sheet_lines['lines']);
        $this->rows($dossier, 'income_statement', $dossier->income_statement_lines['lines']);
    }

    /**
     * @param  array<string, mixed>  $cashFlow  Result of CashFlowStatementBuilder::build().
     */
    public function persistCashFlow(StatutoryDossier $dossier, StatementBundle $bundle, array $cashFlow): void
    {
        $catalog = $this->catalogs->catalog($bundle->standard, 'cash_flow', (string) $cashFlow['method']);
        $exact = $catalog->totals($cashFlow['exact']);

        $dossier->cash_flow_statement = array_merge($cashFlow, [
            'lines' => $this->lines($catalog, $cashFlow['totals'], $exact, null),
            'standard_label' => $bundle->standard->jurisdiction() === 'IT' ? 'OIC 10' : 'FER 4',
        ]);
        $dossier->save();

        DossierLine::query()->where('dossier_id', $dossier->id)->where('statement', 'cash_flow')->forceDelete();
        $this->rows($dossier, 'cash_flow', $dossier->cash_flow_statement['lines']);
    }

    public function rehydrate(StatutoryDossier $dossier): StatementBundle
    {
        $standard = $dossier->accounting_standard;
        $stored = $dossier->quadrature ?? [];
        $method = IncomeStatementMethod::tryFrom((string) ($dossier->income_statement_lines['method'] ?? '')) ?? IncomeStatementMethod::TotalCost;
        $balanceSheet = $this->catalogs->catalog($standard, 'balance_sheet');
        $incomeStatement = $this->catalogs->catalog($standard, 'income_statement', $method->value);

        $bsLines = $dossier->balance_sheet_lines['lines'] ?? [];
        $isLines = $dossier->income_statement_lines['lines'] ?? [];
        $bsTotals = $this->amounts($bsLines, 'amount');
        $isTotals = $this->amounts($isLines, 'amount');
        $report = $dossier->reclassification_report ?? [];

        $quadrature = new QuadratureResult(
            balanceSheetLeaves: $this->leafAmounts($balanceSheet, $bsTotals),
            incomeStatementLeaves: $this->leafAmounts($incomeStatement, $isTotals),
            balanceSheetTotals: $bsTotals,
            incomeStatementTotals: $isTotals,
            incomeStatementResidual: (string) ($stored['income_statement_residual'] ?? '0'),
            balanceSheetResidual: (string) ($stored['balance_sheet_residual'] ?? '0'),
        );

        return new StatementBundle(
            standard: $standard,
            method: $method,
            scale: (int) ($stored['scale'] ?? $dossier->rounding_to_unit_mode->scale()),
            balanceSheet: $balanceSheet,
            incomeStatement: $incomeStatement,
            balanceSheetExact: (array) ($stored['balance_sheet_exact'] ?? []),
            incomeStatementExact: (array) ($stored['income_statement_exact'] ?? []),
            quadrature: $quadrature,
            comparativeBalanceSheet: $this->comparative($bsLines),
            comparativeIncomeStatement: $this->comparative($isLines),
            classification: new ClassificationResult([], (array) ($report['income_leaves'] ?? []), (array) ($report['sources'] ?? []), (array) ($report['bifront'] ?? []), (array) ($report['unmapped'] ?? [])),
            openingBalanceSheetExact: (array) ($stored['opening_balance_sheet_exact'] ?? []),
            openingProfit: (string) ($stored['opening_profit'] ?? '0'),
            codes: (array) ($stored['codes'] ?? []),
            warnings: (array) ($report['warnings'] ?? []),
        );
    }

    /**
     * @param  array<string, string>  $totals
     * @param  array<string, string>  $exact
     * @param  array<string, string>|null  $comparative
     * @return list<array<string, mixed>>
     */
    private function lines(LineCatalog $catalog, array $totals, array $exact, ?array $comparative): array
    {
        $lines = [];
        $levels = [];

        foreach ($catalog->lines() as $code => $line) {
            $level = $line->parentCode !== null && isset($levels[$line->parentCode]) ? $levels[$line->parentCode] + 1 : 0;
            $levels[$code] = $level;
            $lines[] = [
                'code' => $code,
                'parent_code' => $line->parentCode !== null && $catalog->has($line->parentCode) ? $line->parentCode : null,
                'sign' => $line->sign,
                'side' => $line->side,
                'sort_order' => $line->sortOrder,
                'level' => $level,
                'is_leaf' => $catalog->isLeaf($code),
                'is_total' => ! $catalog->isLeaf($code),
                'is_rounding' => $line->isRounding,
                'xbrl_element' => $line->xbrlElement,
                'amount' => $totals[$code] ?? '0',
                'exact_amount' => $exact[$code] ?? '0',
                'comparative_amount' => $comparative !== null ? ($comparative[$code] ?? '0') : null,
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function rows(StatutoryDossier $dossier, string $statement, array $lines): void
    {
        foreach ($lines as $line) {
            DossierLine::query()->create([
                'tenant_id' => $dossier->tenant_id,
                'dossier_id' => $dossier->id,
                'statement' => $statement,
                'line_code' => $line['code'],
                'parent_code' => $line['parent_code'],
                'sort_order' => $line['sort_order'],
                'is_total' => $line['is_total'],
                'is_rounding' => $line['is_rounding'],
                'exact_amount' => $line['exact_amount'],
                'amount' => $line['amount'],
                'comparative_amount' => $line['comparative_amount'],
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, string>
     */
    private function amounts(array $lines, string $key): array
    {
        $amounts = [];

        foreach ($lines as $line) {
            $amounts[(string) $line['code']] = Money::from($line[$key] ?? '0');
        }

        return $amounts;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, string>|null
     */
    private function comparative(array $lines): ?array
    {
        $result = [];

        foreach ($lines as $line) {
            if ($line['comparative_amount'] === null) {
                return null;
            }

            $result[(string) $line['code']] = Money::from($line['comparative_amount']);
        }

        return $result === [] ? null : $result;
    }

    /**
     * @param  array<string, string>  $totals
     * @return array<string, string>
     */
    private function leafAmounts(LineCatalog $catalog, array $totals): array
    {
        $leaves = [];

        foreach ($catalog->leaves() as $code) {
            $leaves[$code] = $totals[$code] ?? '0';
        }

        return $leaves;
    }
}
