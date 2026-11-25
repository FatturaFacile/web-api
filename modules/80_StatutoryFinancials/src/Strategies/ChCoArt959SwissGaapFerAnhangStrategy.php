<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Strategies;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\IncomeStatementMethod;
use Modules\StatutoryFinancials\Domain\Strategy\EligibilityResult;
use Modules\StatutoryFinancials\Domain\Strategy\EntityMetrics;
use Modules\StatutoryFinancials\Support\Money;

/**
 * Switzerland: the minimum structure of the balance sheet and the income statement (CO Art. 959a and 959b, total cost and cost of
 * sales methods), the Anhang (CO Art. 959c), Swiss GAAP FER (FER 1-31, cash flow statement FER 4), hidden reserves (stille Reserven),
 * the legal reserve from profit (5% until 20% of the share capital) and the capital loss / over-indebtedness tests of Art. 725a-725b CO.
 */
final class ChCoArt959SwissGaapFerAnhangStrategy extends AbstractStatutoryStrategy
{
    public function jurisdiction(): string
    {
        return 'CH';
    }

    protected function indicatorFamily(): string
    {
        return 'CH';
    }

    protected function allocationArticle(): string
    {
        return 'Art. 671 CO';
    }

    public function evaluateEligibility(EntityMetrics $metrics, AccountingStandard $chosen): EligibilityResult
    {
        /** @var array<string, string> $limits */
        $limits = config('statutory_financials.ch_thresholds');
        $exceeded = [];

        foreach (['total_assets' => $metrics->totalAssets, 'revenue' => $metrics->revenue, 'average_employees' => $metrics->averageEmployees] as $criterion => $value) {
            if (Money::compare($value, $limits[$criterion]) > 0) {
                $exceeded[] = $criterion;
            }
        }

        $large = count($exceeded) >= 2;
        $required = ['balance_sheet', 'income_statement', 'notes'];

        if ($large || $chosen === AccountingStandard::ChSwissGaapFer) {
            $required = ['balance_sheet', 'income_statement', 'cash_flow', 'notes', 'management_report'];
        }

        return new EligibilityResult(
            recommendedStandard: $chosen->value,
            chosenAllowed: true,
            exceeded: $exceeded,
            limits: ['assets_limit' => $limits['total_assets'], 'revenue_limit' => $limits['revenue'], 'employees_limit' => $limits['average_employees']],
            messageKey: 'eligibility.ch_co',
            warnings: [],
            requiredStatements: $required,
        );
    }

    public function incomeStatementMethods(AccountingStandard $standard): array
    {
        return [IncomeStatementMethod::TotalCost, IncomeStatementMethod::CostOfSales];
    }

    public function cashFlowMethods(AccountingStandard $standard): array
    {
        return [CashFlowMethod::Indirect, CashFlowMethod::Direct];
    }

    public function cashFlowRequired(AccountingStandard $standard, EntityMetrics $metrics): bool
    {
        return $standard === AccountingStandard::ChSwissGaapFer
            || $this->evaluateEligibility($metrics, $standard)->requiredStatements !== ['balance_sheet', 'income_statement', 'notes'];
    }

    public function roundingCodes(AccountingStandard $standard, IncomeStatementMethod $method): array
    {
        $is = $method === IncomeStatementMethod::CostOfSales ? 'ch.is.cs.' : 'ch.is.tc.';

        return [
            'bs_profit' => 'ch.bs.profit', 'bs_reserve' => 'ch.bs.round_res', 'bs_assets' => 'ch.bs.assets', 'bs_liabilities' => 'ch.bs.liabilities',
            'is_profit' => $is.'profit', 'is_round_income' => $is.'round_inc', 'is_round_cost' => $is.'round_cost',
        ];
    }

    public function noteStructure(AccountingStandard $standard): array
    {
        $sections = ['ch.notes.principles', 'ch.notes.fixed_assets', 'ch.notes.liabilities', 'ch.notes.participations', 'ch.notes.fte', 'ch.notes.leasing', 'ch.notes.guarantees', 'ch.notes.treasury_shares', 'ch.notes.hidden_reserves', 'ch.notes.related_parties', 'ch.notes.subsequent_events', 'ch.notes.profit_allocation'];

        if ($standard === AccountingStandard::ChSwissGaapFer) {
            array_splice($sections, 1, 0, ['ch.notes.fer_framework']);
        }

        return ['sections' => $sections, 'tables' => ['fixed_assets', 'equity', 'maturity', 'leases', 'off_balance', 'workforce', 'related_parties', 'taxes']];
    }

    public function pdfAProfile(): string
    {
        return 'PDF/A-2b';
    }

    public function minutesTemplates(): array
    {
        return ['board' => 'ch.minutes.board', 'shareholders' => 'ch.minutes.shareholders'];
    }

    public function cashFlowLabelKey(): string
    {
        return 'FER 4';
    }
}
