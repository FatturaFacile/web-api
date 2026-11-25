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
 * Italy: civil-code financial statements (Art. 2424-2425, 2435-bis, 2435-ter c.c.) with the OIC 10 cash flow statement (indirect and
 * direct), the XBRL Italia / InfoCamere deposit, the PDF/A-1b documents and the early-warning indicators of Art. 2086 c.c. and
 * Art. 13 D.Lgs. 14/2019 (six-month DSCR, equity, sector indices).
 */
final class ItXbrlOic10CrisiImpresaStrategy extends AbstractStatutoryStrategy
{
    public function jurisdiction(): string
    {
        return 'IT';
    }

    protected function indicatorFamily(): string
    {
        return 'IT';
    }

    protected function allocationArticle(): string
    {
        return 'Art. 2430 c.c.';
    }

    public function evaluateEligibility(EntityMetrics $metrics, AccountingStandard $chosen): EligibilityResult
    {
        /** @var array{micro: array<string, string>, abbreviated: array<string, string>} $limits */
        $limits = config('statutory_financials.it_thresholds');
        $microExceeded = $this->exceeded($metrics, $limits['micro']);
        $abbreviatedExceeded = $this->exceeded($metrics, $limits['abbreviated']);

        [$recommended, $message] = match (true) {
            count($microExceeded) <= 1 => [AccountingStandard::ItMicro, 'eligibility.micro'],
            count($abbreviatedExceeded) <= 1 => [AccountingStandard::ItAbbreviato, 'eligibility.abbreviated'],
            default => [AccountingStandard::ItOrdinario, 'eligibility.ordinary'],
        };

        $allowed = match ($chosen) {
            AccountingStandard::ItMicro => count($microExceeded) <= 1,
            AccountingStandard::ItAbbreviato => count($abbreviatedExceeded) <= 1,
            default => true,
        };

        $warnings = [];

        if ($chosen !== $recommended) {
            $warnings[] = ['code' => 'validation.eligibility_override', 'params' => ['standard' => $chosen->value, 'recommended' => $recommended->value]];
        }

        $applied = match ($chosen) {
            AccountingStandard::ItMicro => $limits['micro'],
            default => $limits['abbreviated'],
        };

        return new EligibilityResult(
            recommendedStandard: $recommended->value,
            chosenAllowed: $allowed,
            exceeded: $chosen === AccountingStandard::ItMicro ? $microExceeded : $abbreviatedExceeded,
            limits: ['assets_limit' => $applied['total_assets'], 'revenue_limit' => $applied['revenue'], 'employees_limit' => $applied['average_employees']],
            messageKey: $message,
            warnings: $warnings,
            requiredStatements: $this->requiredStatements($chosen),
        );
    }

    public function incomeStatementMethods(AccountingStandard $standard): array
    {
        return [IncomeStatementMethod::TotalCost];
    }

    public function cashFlowMethods(AccountingStandard $standard): array
    {
        return [CashFlowMethod::Indirect, CashFlowMethod::Direct];
    }

    public function cashFlowRequired(AccountingStandard $standard, EntityMetrics $metrics): bool
    {
        return $standard === AccountingStandard::ItOrdinario;
    }

    public function roundingCodes(AccountingStandard $standard, IncomeStatementMethod $method): array
    {
        return [
            'bs_profit' => 'it.bs.pn.ix', 'bs_reserve' => 'it.bs.pn.round', 'bs_assets' => 'it.bs.assets', 'bs_liabilities' => 'it.bs.liabilities',
            'is_profit' => 'it.is.profit', 'is_round_income' => 'it.is.a.round', 'is_round_cost' => 'it.is.b.round',
        ];
    }

    public function noteStructure(AccountingStandard $standard): array
    {
        return match ($standard) {
            AccountingStandard::ItMicro => ['sections' => [], 'tables' => []],
            AccountingStandard::ItAbbreviato => [
                'sections' => ['it.notes.criteri_valutazione', 'it.notes.crediti_debiti', 'it.notes.patrimonio_netto', 'it.notes.compensi', 'it.notes.dipendenti', 'it.notes.impegni', 'it.notes.imposte', 'it.notes.fatti_successivi', 'it.notes.destinazione_risultato'],
                'tables' => ['fixed_assets', 'equity', 'maturity', 'compensation', 'workforce'],
            ],
            default => [
                'sections' => ['it.notes.criteri_valutazione', 'it.notes.immobilizzazioni', 'it.notes.crediti_debiti', 'it.notes.patrimonio_netto', 'it.notes.compensi', 'it.notes.dipendenti', 'it.notes.leasing', 'it.notes.impegni', 'it.notes.parti_correlate', 'it.notes.imposte', 'it.notes.fatti_successivi', 'it.notes.continuita', 'it.notes.destinazione_risultato'],
                'tables' => ['fixed_assets', 'equity', 'maturity', 'compensation', 'leases', 'off_balance', 'workforce', 'related_parties', 'taxes'],
            ],
        };
    }

    public function pdfAProfile(): string
    {
        return 'PDF/A-1b';
    }

    public function minutesTemplates(): array
    {
        return ['board' => 'it.minutes.board', 'shareholders' => 'it.minutes.shareholders'];
    }

    public function cashFlowLabelKey(): string
    {
        return 'OIC 10';
    }

    /**
     * @param  array<string, string>  $limits
     * @return list<string>
     */
    private function exceeded(EntityMetrics $metrics, array $limits): array
    {
        $exceeded = [];

        foreach (['total_assets' => $metrics->totalAssets, 'revenue' => $metrics->revenue, 'average_employees' => $metrics->averageEmployees] as $criterion => $value) {
            if (Money::compare($value, $limits[$criterion]) > 0) {
                $exceeded[] = $criterion;
            }
        }

        return $exceeded;
    }

    /**
     * @return list<string>
     */
    private function requiredStatements(AccountingStandard $standard): array
    {
        return match ($standard) {
            AccountingStandard::ItMicro => ['balance_sheet', 'income_statement'],
            AccountingStandard::ItAbbreviato => ['balance_sheet', 'income_statement', 'notes'],
            default => ['balance_sheet', 'income_statement', 'cash_flow', 'notes', 'management_report'],
        };
    }
}
