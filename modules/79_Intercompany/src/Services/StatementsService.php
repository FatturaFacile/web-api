<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use App\Models\User;
use Modules\GeneralLedger\Models\ConsolidationRun;
use Modules\GeneralLedger\Services\Consolidation\ConsolidatedStatementService;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationMath;
use Modules\GeneralLedger\Services\Consolidation\ConsolidationService;
use Modules\Intercompany\Enums\Standard;
use Modules\Intercompany\Support\GroupScope;

/**
 * The consolidated balance sheet, income statement and cash flow of a run in a reporting standard (Swiss GAAP FER 30, IFRS 10,
 * OIC 17). Every line carries the three columns the controller reads: the standalone sum of the entities, the
 * eliminations and adjustments (generated and manual), and the consolidated amount. Totals and the equity bridge (result of
 * the period, translation reserve, non-controlling interests) come from the consolidation module's own statements, so the
 * figures cannot drift from the ones of the run endpoints; the standard only decides order, layout and disclosures.
 */
final readonly class StatementsService
{
    public function __construct(
        private ConsolidationService $consolidation,
        private ConsolidatedStatementService $statements,
        private ConsolidatedCashFlowService $cashFlow,
        private RunFigures $figures,
        private GroupScope $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function statements(User $user, ConsolidationRun $run, Standard $standard, string $which = 'all'): array
    {
        $this->consolidation->assertCanReadRun($user, $run);
        $profile = StandardProfile::for($standard);
        $accounts = $this->figures->accounts($run);
        $nci = array_values($run->totals['non_controlling_interests'] ?? []);
        $group = $this->scope->groupOfRun($run);
        $codes = $this->statements->codesOf($group);
        $cta = (string) $group->translation_reserve_code;

        $result = [
            'run_id' => (string) $run->id,
            'standard' => $standard->value,
            'currency' => (string) $run->group_currency,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'translation_reserve' => isset($accounts[$cta]) ? ConsolidationMath::neg($accounts[$cta]['net']) : ConsolidationMath::zero(),
            'nci' => [
                'net_assets' => array_reduce($nci, static fn (string $carry, array $row): string => ConsolidationMath::add($carry, (string) $row['nci_net_assets']), ConsolidationMath::zero()),
                'profit' => array_reduce($nci, static fn (string $carry, array $row): string => ConsolidationMath::add($carry, (string) $row['nci_profit']), ConsolidationMath::zero()),
            ],
            'presentation' => $profile,
        ];

        if (in_array($which, ['all', 'balance_sheet'], true)) {
            $result['balance_sheet'] = $this->balanceSheet($accounts, $nci, $codes, $profile, $cta);
        }

        if (in_array($which, ['all', 'income_statement'], true)) {
            $result['income_statement'] = $this->incomeStatement($accounts, $nci, $codes, $profile);
        }

        if (in_array($which, ['all', 'cash_flow'], true)) {
            $result['cash_flow'] = $this->cashFlow->cashFlow($user, $run);
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, string>>  $accounts
     * @param  list<array<string, string>>  $nci
     * @param  array<string, string>  $codes
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function balanceSheet(array $accounts, array $nci, array $codes, array $profile, string $cta): array
    {
        $totals = $this->statements->balanceSheetOf($accounts, $nci, $codes);
        $sections = [
            'assets' => $this->section('assets', $accounts, ['asset', 'wip'], 1),
            'liabilities' => $this->section('liabilities', $accounts, ['liability'], -1),
            'equity' => $this->section('equity', $accounts, ['equity'], -1),
        ];

        $income = $this->incomeTotals($accounts);
        $sections['equity']['lines'][] = [
            'account_code' => 'CONS-RESULT',
            'account_name' => 'Result of the period',
            'standalone' => ConsolidationMath::neg($income['aggregated']),
            'eliminations' => ConsolidationMath::neg($income['adjustments']),
            'consolidated' => $totals['result_of_the_period'],
        ];
        $sections['equity']['total'] = [
            'standalone' => ConsolidationMath::add($sections['equity']['total']['standalone'], ConsolidationMath::neg($income['aggregated'])),
            'eliminations' => ConsolidationMath::add($sections['equity']['total']['eliminations'], ConsolidationMath::neg($income['adjustments'])),
            'consolidated' => $totals['total_equity'],
        ];

        $ordered = [];

        foreach ($profile['balance_sheet_order'] as $code) {
            $ordered[] = $sections[$code];
        }

        return [
            'sections' => $ordered,
            'totals' => [
                'total_assets' => $totals['total_assets'],
                'total_liabilities' => $totals['total_liabilities'],
                'total_equity' => $totals['total_equity'],
                'translation_reserve' => isset($accounts[$cta]) ? ConsolidationMath::neg($accounts[$cta]['net']) : ConsolidationMath::zero(),
                'non_controlling_interests' => $totals['non_controlling_interests'],
                'equity_attributable_to_owners' => $totals['equity_attributable_to_owners'],
                'total_liabilities_and_equity' => $totals['total_liabilities_and_equity'],
                'is_balanced' => $totals['is_balanced'],
            ],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $accounts
     * @param  list<array<string, string>>  $nci
     * @param  array<string, string>  $codes
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function incomeStatement(array $accounts, array $nci, array $codes, array $profile): array
    {
        $totals = $this->statements->incomeStatementOf($accounts, $nci, $codes);
        $revenue = $this->section('revenue', $accounts, ['revenue'], -1);
        $cogs = $this->section('cogs', $accounts, ['cogs'], 1);
        $expenses = $this->section('operating_expenses', $accounts, ['expense'], 1);

        $sections = $profile['income_layout'] === 'nature'
            ? [
                [...$revenue, 'code' => 'value_of_production', 'label_key' => 'intercompany.statements.section.value_of_production'],
                $this->merge('cost_of_production', [$cogs, $expenses]),
            ]
            : [$revenue, $cogs, $expenses];

        return [
            'layout' => $profile['income_layout'],
            'sections' => $sections,
            'totals' => [
                'total_revenue' => $totals['total_revenue'],
                'gross_profit' => $totals['gross_profit'],
                'income_tax' => $totals['income_tax'],
                'net_income' => $totals['net_income'],
                'net_income_attributable_to_non_controlling_interests' => $totals['net_income_attributable_to_non_controlling_interests'],
                'net_income_attributable_to_owners' => $totals['net_income_attributable_to_owners'],
            ],
        ];
    }

    /**
     * @param  array<string, array<string, string>>  $accounts
     * @param  list<string>  $types
     * @return array{code: string, label_key: string, lines: list<array<string, string>>, total: array<string, string>}
     */
    private function section(string $code, array $accounts, array $types, int $sign): array
    {
        $lines = [];
        $total = ['standalone' => ConsolidationMath::zero(), 'eliminations' => ConsolidationMath::zero(), 'consolidated' => ConsolidationMath::zero()];

        foreach ($accounts as $account) {
            if (! in_array($account['account_type'], $types, true)) {
                continue;
            }

            $line = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'standalone' => $this->signed($account['aggregated_net'], $sign),
                'eliminations' => $this->signed($account['adjustments_net'], $sign),
                'consolidated' => $this->signed($account['net'], $sign),
            ];
            $lines[] = $line;

            foreach (['standalone', 'eliminations', 'consolidated'] as $column) {
                $total[$column] = ConsolidationMath::add($total[$column], $line[$column]);
            }
        }

        return ['code' => $code, 'label_key' => 'intercompany.statements.section.'.$code, 'lines' => $lines, 'total' => $total];
    }

    /**
     * @param  list<array{code: string, label_key: string, lines: list<array<string, string>>, total: array<string, string>}>  $sections
     * @return array{code: string, label_key: string, lines: list<array<string, string>>, total: array<string, string>}
     */
    private function merge(string $code, array $sections): array
    {
        $lines = [];
        $total = ['standalone' => ConsolidationMath::zero(), 'eliminations' => ConsolidationMath::zero(), 'consolidated' => ConsolidationMath::zero()];

        foreach ($sections as $section) {
            foreach ($section['lines'] as $line) {
                $lines[] = $line;
            }

            foreach ($total as $column => $value) {
                $total[$column] = ConsolidationMath::add($value, $section['total'][$column]);
            }
        }

        return ['code' => $code, 'label_key' => 'intercompany.statements.section.'.$code, 'lines' => $lines, 'total' => $total];
    }

    /**
     * @param  array<string, array<string, string>>  $accounts
     * @return array{aggregated: string, adjustments: string}
     */
    private function incomeTotals(array $accounts): array
    {
        $aggregated = $adjustments = ConsolidationMath::zero();

        foreach ($accounts as $account) {
            if (in_array($account['account_type'], ['revenue', 'cogs', 'expense'], true)) {
                $aggregated = ConsolidationMath::add($aggregated, $account['aggregated_net']);
                $adjustments = ConsolidationMath::add($adjustments, $account['adjustments_net']);
            }
        }

        return ['aggregated' => $aggregated, 'adjustments' => $adjustments];
    }

    private function signed(string $amount, int $sign): string
    {
        return $sign < 0 ? ConsolidationMath::neg($amount) : ConsolidationMath::norm($amount);
    }
}
