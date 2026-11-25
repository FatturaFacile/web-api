<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Contracts;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Strategy\EligibilityResult;
use Modules\StatutoryFinancials\Domain\Strategy\EntityMetrics;
use Modules\StatutoryFinancials\Domain\Strategy\FinancialSnapshot;
use Modules\StatutoryFinancials\Domain\Strategy\ProfitAllocationInput;
use Modules\StatutoryFinancials\Domain\Strategy\XbrlProfile;

/**
 * Jurisdiction rules of the statutory reporting: which scheme a company may use, which statements and notes are mandatory, the
 * rounding lines, the profit allocation, the crisis indicators, the XBRL package and the PDF/A level. One implementation per
 * jurisdiction, resolved by the accounting standard of the dossier.
 */
interface IStatutoryReportingComplianceStrategy
{
    /**
     * "IT" or "CH".
     */
    public function jurisdiction(): string;

    public function supports(AccountingStandard $standard): bool;

    /**
     * Size thresholds and the scheme they allow, with warnings when the chosen one is not the allowed one.
     */
    public function evaluateEligibility(EntityMetrics $metrics, AccountingStandard $chosen): EligibilityResult;

    /**
     * @return list<IncomeStatementMethod>
     */
    public function incomeStatementMethods(AccountingStandard $standard): array;

    /**
     * @return list<CashFlowMethod>
     */
    public function cashFlowMethods(AccountingStandard $standard): array;

    /**
     * Whether the standard requires a cash flow statement (OIC 10 / FER 4).
     */
    public function cashFlowRequired(AccountingStandard $standard, EntityMetrics $metrics): bool;

    /**
     * Lines that receive the rounding residuals.
     *
     * @return array{bs_profit: string, bs_reserve: string, bs_assets: string, bs_liabilities: string, is_profit: string, is_round_income: string, is_round_cost: string}
     */
    public function roundingCodes(AccountingStandard $standard, IncomeStatementMethod $method): array;

    /**
     * Template keys of the narrative sections and names of the quantitative tables of the notes.
     *
     * @return array{sections: list<string>, tables: list<string>}
     */
    public function noteStructure(AccountingStandard $standard): array;

    /**
     * Proposal for the allocation of the profit.
     *
     * @return array<string, mixed>
     */
    public function profitAllocation(ProfitAllocationInput $input): array;

    /**
     * Ratios, crisis indicators and their alert level.
     *
     * @param  array<string, string>  $whatIf  Overrides of the simulated inputs (what-if parameters).
     * @return array<string, mixed>
     */
    public function indicators(FinancialSnapshot $snapshot, array $whatIf = []): array;

    public function xbrlProfile(AccountingStandard $standard): XbrlProfile;

    /**
     * "PDF/A-1b" or "PDF/A-2b".
     */
    public function pdfAProfile(): string;

    /**
     * @return array{board: string, shareholders: string}
     */
    public function minutesTemplates(): array;

    public function cashFlowLabelKey(): string;
}
