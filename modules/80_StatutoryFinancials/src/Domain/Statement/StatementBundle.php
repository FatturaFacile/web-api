<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Statement;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Rounding\QuadratureResult;

/**
 * Everything the generation of the statements produced: catalogues, exact and rounded amounts, comparative amounts and the
 * reclassification report.
 */
final readonly class StatementBundle
{
    /**
     * @param  array<string, string>  $balanceSheetExact  Exact leaf amounts (including the result of the year).
     * @param  array<string, string>  $incomeStatementExact
     * @param  array<string, string>|null  $comparativeBalanceSheet  Rounded line amounts of the prior year.
     * @param  array<string, string>|null  $comparativeIncomeStatement
     * @param  array<string, string>  $openingBalanceSheetExact  Leaves of the balance sheet at the start of the year.
     * @param  array{bs_profit: string, bs_reserve: string, bs_assets: string, bs_liabilities: string, is_profit: string, is_round_income: string, is_round_cost: string}  $codes
     * @param  list<array{code: string, severity: string, params: array<string, string>}>  $warnings
     */
    public function __construct(
        public AccountingStandard $standard,
        public IncomeStatementMethod $method,
        public int $scale,
        public LineCatalog $balanceSheet,
        public LineCatalog $incomeStatement,
        public array $balanceSheetExact,
        public array $incomeStatementExact,
        public QuadratureResult $quadrature,
        public ?array $comparativeBalanceSheet,
        public ?array $comparativeIncomeStatement,
        public ClassificationResult $classification,
        public array $openingBalanceSheetExact,
        public string $openingProfit,
        public array $codes,
        public array $warnings,
    ) {}
}
