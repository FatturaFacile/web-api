<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\StatutoryFinancials\Contracts\CompensationRegisterPort;
use Modules\StatutoryFinancials\Contracts\DueDateSchedulePort;
use Modules\StatutoryFinancials\Contracts\FixedAssetRegisterPort;
use Modules\StatutoryFinancials\Contracts\LeaseRegisterPort;
use Modules\StatutoryFinancials\Contracts\WorkforceRegisterPort;
use Modules\StatutoryFinancials\Domain\Statement\StatementBundle;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;
use Modules\StatutoryFinancials\Support\Money;

/**
 * The Nota Integrativa (IT) / Anhang (CH) workspace: quantitative tables filled from the subledgers through query ports (fixed assets
 * Mod 019, due dates Mod 017, leases Mod 147, workforce Mod 072, ledger compensation accounts) and from the statements, and the
 * narrative sections created from the seeded templates. Tables the user edited by hand (flag `manual`) and narrative sections the
 * user edited are never overwritten by a new population.
 */
final class NotesService
{
    public function __construct(
        private readonly FixedAssetRegisterPort $assets,
        private readonly DueDateSchedulePort $dueDates,
        private readonly LeaseRegisterPort $leases,
        private readonly WorkforceRegisterPort $workforce,
        private readonly CompensationRegisterPort $compensation,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly TextTemplateService $templates,
    ) {}

    /**
     * @param  array<string, mixed>|null  $existingTables
     * @param  list<array<string, mixed>>|null  $existingSections
     * @return array{tables: array<string, mixed>, sections: list<array<string, mixed>>, sources: array<string, string>}
     */
    public function populate(StatutoryDossier $dossier, StatementBundle $bundle, ?array $existingTables = null, ?array $existingSections = null): array
    {
        $year = FiscalYear::query()->findOrFail($dossier->fiscal_year_id);
        $tenantId = (string) $dossier->tenant_id;
        $from = CarbonImmutable::parse($year->start_date);
        $to = CarbonImmutable::parse($year->end_date);
        $family = $dossier->accounting_standard->jurisdiction();
        $strategy = $this->strategies->for($dossier->accounting_standard);
        $structure = $strategy->noteStructure($dossier->accounting_standard);
        $existingTables ??= [];

        $generated = [
            'fixed_assets' => $this->fixedAssets($tenantId, $from, $to, $bundle, $dossier->locale),
            'equity' => $this->equity($bundle, $dossier->locale),
            'maturity' => $this->maturity($tenantId, $to, $bundle),
            'compensation' => $this->compensationTable($tenantId, $family, $from, $to),
            'leases' => $this->leaseTable($tenantId, $from, $to),
            'workforce' => $this->workforceTable($tenantId, $from, $to),
            'off_balance' => ['source' => 'manual', 'available' => true, 'rows' => []],
            'related_parties' => ['source' => 'manual', 'available' => true, 'rows' => []],
            'taxes' => $this->taxes($bundle),
        ];

        $tables = [];
        $sources = [];

        foreach ($structure['tables'] as $name) {
            $table = $generated[$name] ?? ['source' => 'manual', 'available' => true, 'rows' => []];
            $previous = $existingTables[$name] ?? null;

            if (is_array($previous) && ($previous['manual'] ?? false) === true) {
                $table = $previous;
            }

            $tables[$name] = $table;
            $sources[$name] = (string) ($table['source'] ?? 'manual');
        }

        return ['tables' => $tables, 'sections' => $this->sections($dossier, $bundle, $structure['sections'], $existingSections), 'sources' => $sources];
    }

    /**
     * @param  list<string>  $keys
     * @param  list<array<string, mixed>>|null  $existing
     * @return list<array<string, mixed>>
     */
    public function sections(StatutoryDossier $dossier, StatementBundle $bundle, array $keys, ?array $existing = null): array
    {
        $params = $this->placeholders($dossier, $bundle);
        $byKey = [];

        foreach ($existing ?? [] as $section) {
            $byKey[(string) ($section['key'] ?? '')] = $section;
        }

        $sections = [];

        foreach ($keys as $key) {
            if (isset($byKey[$key]) && ($byKey[$key]['edited'] ?? false) === true) {
                $sections[] = $byKey[$key];

                continue;
            }

            $rendered = $this->templates->render($key, $dossier->locale, $params);
            $sections[] = ['key' => $key, 'title' => $rendered['title'], 'body' => $rendered['body'], 'edited' => false];
        }

        return $sections;
    }

    /**
     * Placeholders common to the notes, the management report and the minutes.
     *
     * @return array<string, string>
     */
    public function placeholders(StatutoryDossier $dossier, StatementBundle $bundle): array
    {
        $tenant = Tenant::query()->find($dossier->tenant_id);
        $year = FiscalYear::query()->find($dossier->fiscal_year_id);
        $totals = $bundle->quadrature->balanceSheetTotals;
        $isTotals = $bundle->quadrature->incomeStatementTotals;
        $family = $bundle->standard->jurisdiction();
        $revenueCode = $family === 'IT' ? 'it.is.a.1' : ($bundle->method->value === 'cost_of_sales' ? 'ch.is.cs.rev' : 'ch.is.tc.rev');
        $ebitCode = match (true) {
            $family === 'IT' => 'it.is.diff',
            $bundle->method->value === 'cost_of_sales' => 'ch.is.cs.ebit',
            default => 'ch.is.tc.ebit',
        };
        $depreciation = $bundle->classification->incomeLeaves[$family === 'IT' ? 'it.is.b.10' : 'ch.is.tc.depr'] ?? '0';
        $equityCode = $family === 'IT' ? 'it.bs.pn' : 'ch.bs.equity';

        return [
            'company_name' => (string) ($tenant->legal_name ?? $tenant->name ?? ''),
            'fiscal_year' => (string) ($year->year_code ?? ''),
            'net_result' => $this->format($isTotals[$bundle->codes['is_profit']] ?? '0', $bundle->scale),
            'currency' => $dossier->currency,
            'standard' => $bundle->standard->legalReference(),
            'legal_reference' => $bundle->standard->legalReference(),
            'revenue' => $this->format($isTotals[$revenueCode] ?? '0', $bundle->scale),
            'ebitda' => $this->format(Money::add($isTotals[$ebitCode] ?? '0', Money::round($depreciation, $bundle->scale)), $bundle->scale),
            'total_assets' => $this->format($totals[$bundle->codes['bs_assets']] ?? '0', $bundle->scale),
            'equity' => $this->format($totals[$equityCode] ?? '0', $bundle->scale),
        ];
    }

    private function format(string $amount, int $scale): string
    {
        return number_format((float) Money::round($amount, $scale), $scale, '.', "'");
    }

    /**
     * @return array<string, mixed>
     */
    private function fixedAssets(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, StatementBundle $bundle, string $locale): array
    {
        $result = $this->assets->movements($tenantId, $from, $to);

        if ($result['available'] && $result['rows'] !== []) {
            $columns = ['opening_cost', 'additions', 'disposals_cost', 'revaluations', 'impairments', 'closing_cost', 'opening_accumulated', 'depreciation', 'disposals_accumulated', 'closing_accumulated', 'net_book_value'];
            $totals = [];

            foreach ($columns as $column) {
                $totals[$column] = Money::sum(array_column($result['rows'], $column));
            }

            return ['source' => $result['source'], 'available' => true, 'fallback' => false, 'rows' => $result['rows'], 'totals' => $totals];
        }

        $rows = [];
        $lines = $bundle->balanceSheet->lines();

        foreach ($bundle->balanceSheet->leaves() as $code) {
            if (! in_array($lines[$code]->cfGroup, ['invest_tangible', 'invest_intangible', 'invest_financial'], true)) {
                continue;
            }

            $closing = $bundle->quadrature->balanceSheetLeaves[$code] ?? '0';
            $rows[] = ['category_id' => $code, 'category' => $lines[$code]->label($locale), 'asset_type' => $lines[$code]->cfGroup, 'opening_cost' => '0', 'additions' => '0', 'disposals_cost' => '0', 'revaluations' => '0', 'impairments' => '0', 'closing_cost' => $closing, 'opening_accumulated' => '0', 'depreciation' => '0', 'disposals_accumulated' => '0', 'closing_accumulated' => '0', 'net_book_value' => $closing];
        }

        return ['source' => 'balance_sheet', 'available' => false, 'fallback' => true, 'rows' => $rows, 'totals' => ['closing_cost' => Money::sum(array_column($rows, 'closing_cost')), 'net_book_value' => Money::sum(array_column($rows, 'net_book_value'))]];
    }

    /**
     * @return array<string, mixed>
     */
    private function equity(StatementBundle $bundle, string $locale): array
    {
        $lines = $bundle->balanceSheet->lines();
        $rows = [];

        foreach ($bundle->balanceSheet->leaves() as $code) {
            $group = $lines[$code]->cfGroup;

            if ($lines[$code]->side !== 'liability_equity' || ! in_array($group, ['fin_equity', 'profit', 'rounding'], true)) {
                continue;
            }

            $opening = Money::round($bundle->openingBalanceSheetExact[$code] ?? '0', $bundle->scale);
            $closing = $bundle->quadrature->balanceSheetLeaves[$code] ?? '0';
            $isProfit = $group === 'profit';
            $rows[] = [
                'line_code' => $code,
                'label' => $lines[$code]->label($locale),
                'opening' => $isProfit ? $opening : $opening,
                'movements' => $isProfit ? '0' : Money::sub($closing, $opening),
                'result_for_year' => $isProfit ? $closing : '0',
                'allocation_of_prior_result' => $isProfit ? Money::neg($opening) : '0',
                'closing' => $closing,
            ];
        }

        return [
            'source' => 'balance_sheet', 'available' => true, 'rows' => $rows,
            'totals' => ['opening' => Money::sum(array_column($rows, 'opening')), 'closing' => Money::sum(array_column($rows, 'closing'))],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function maturity(string $tenantId, CarbonImmutable $to, StatementBundle $bundle): array
    {
        $result = $this->dueDates->maturities($tenantId, $to);

        if ($result['available'] && (Money::isPositive($result['receivables']['total']) || Money::isPositive($result['payables']['total']))) {
            return ['source' => $result['source'], 'available' => true, 'fallback' => false, 'basis' => 'open_items', 'receivables' => $result['receivables'], 'payables' => $result['payables'], 'payables_due_6m' => $result['payables_due_6m']];
        }

        $lines = $bundle->balanceSheet->lines();
        $receivables = '0';
        $payables = '0';

        foreach ($bundle->balanceSheet->leaves() as $code) {
            $amount = $bundle->quadrature->balanceSheetLeaves[$code] ?? '0';

            if ($lines[$code]->cfGroup === 'wc_receivables') {
                $receivables = Money::add($receivables, $amount);
            } elseif (in_array($lines[$code]->cfGroup, ['wc_payables', 'fin_debt'], true)) {
                $payables = Money::add($payables, $amount);
            }
        }

        $bucket = static fn (string $total): array => ['within_1y' => $total, 'between_1y_5y' => '0', 'beyond_5y' => '0', 'total' => $total, 'secured' => '0'];

        return ['source' => 'balance_sheet', 'available' => false, 'fallback' => true, 'basis' => 'balance_sheet', 'receivables' => $bucket($receivables), 'payables' => $bucket($payables), 'payables_due_6m' => '0'];
    }

    /**
     * @return array<string, mixed>
     */
    private function compensationTable(string $tenantId, string $family, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = $this->compensation->compensation($tenantId, $family, $from, $to);

        return ['source' => $result['source'], 'available' => $result['available'], 'rows' => $result['rows']];
    }

    /**
     * @return array<string, mixed>
     */
    private function leaseTable(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = $this->leases->commitments($tenantId, $from, $to);
        $totals = [];

        foreach (['within_1y', 'between_1y_5y', 'beyond_5y', 'total_commitment', 'closing_liability', 'interest_year', 'redemption_value'] as $column) {
            $totals[$column] = Money::sum(array_column($result['rows'], $column));
        }

        return ['source' => $result['source'], 'available' => $result['available'], 'rows' => $result['rows'], 'totals' => $totals, 'due_6m' => $result['due_6m']];
    }

    /**
     * @return array<string, mixed>
     */
    private function workforceTable(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = $this->workforce->averageHeadcount($tenantId, $from, $to);

        return ['source' => $result['source'], 'available' => $result['available'], 'rows' => $result['rows'], 'total_headcount' => $result['total_headcount'], 'total_fte' => $result['total_fte']];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxes(StatementBundle $bundle): array
    {
        $taxCode = $bundle->standard->jurisdiction() === 'IT' ? 'it.is.tax' : ($bundle->method->value === 'cost_of_sales' ? 'ch.is.cs.tax' : 'ch.is.tc.tax');
        $current = $bundle->quadrature->incomeStatementTotals[$taxCode] ?? '0';

        return ['source' => 'income_statement', 'available' => true, 'rows' => [['kind' => 'current', 'amount' => $current], ['kind' => 'deferred', 'amount' => '0']], 'total' => $current];
    }
}
