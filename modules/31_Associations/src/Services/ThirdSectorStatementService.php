<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Models\StatementMapping;
use Modules\Associations\Support\Money;
use Modules\Associations\Support\ThirdSectorScheme;

/**
 * Builds the statements of a third-sector body from the posted entries of the general ledger. Every amount comes from
 * an account through the mapping table; an account with movements and no mapping is reported by the checks instead of
 * being guessed into a line. Year-end closing entries are left out, so the management report shows the year and the
 * balance sheet shows the balances with the result on its own line.
 */
final class ThirdSectorStatementService
{
    private const array ProfitAndLossTypes = ['revenue', 'expense', 'cogs'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  list<string>|null  $costCenterIds  limit to these cost centres (sections); null for the whole body
     * @return array{year: int, lines: list<array<string, mixed>>, sections: array<string, array{costs: string, revenues: string, result: string}>, totals: array<string, string>, unmapped: list<array<string, string>>}
     */
    public function managementReport(int $year, ?array $costCenterIds = null): array
    {
        $current = $this->managementFigures($year, $costCenterIds);
        $previous = $this->managementFigures($year - 1, $costCenterIds);
        $lines = [];

        foreach (ThirdSectorScheme::ManagementReport as $code => $label) {
            $lines[] = [
                'code' => $code,
                'label' => $label,
                'section' => ThirdSectorScheme::section($code),
                'side' => ThirdSectorScheme::isRevenue($code) ? 'revenue' : 'cost',
                'current' => $current['lines'][$code] ?? '0.00',
                'previous' => $previous['lines'][$code] ?? '0.00',
            ];
        }

        return [
            'year' => $year,
            'lines' => $lines,
            'sections' => $current['sections'],
            'totals' => $current['totals'] + ['previous_result' => $previous['totals']['result']],
            'unmapped' => $current['unmapped'],
        ];
    }

    /**
     * @return array{year: int, lines: list<array<string, mixed>>, totals: array<string, string>, unmapped: list<array<string, string>>}
     */
    public function balanceSheet(int $year): array
    {
        $current = $this->balanceFigures($year);
        $previous = $this->balanceFigures($year - 1);
        $lines = [];

        foreach ([...ThirdSectorScheme::BalanceSheet, ThirdSectorScheme::ResultLine => 'Avanzo/disavanzo d\'esercizio'] as $code => $label) {
            $lines[] = ['code' => $code, 'label' => $label, 'side' => ThirdSectorScheme::isAsset($code) ? 'asset' : 'liability', 'current' => $current['lines'][$code] ?? '0.00', 'previous' => $previous['lines'][$code] ?? '0.00'];
        }

        return ['year' => $year, 'lines' => $lines, 'totals' => $current['totals'], 'unmapped' => $current['unmapped']];
    }

    /**
     * @param  list<string>|null  $costCenterIds
     * @return array{year: int, lines: list<array<string, mixed>>, sections: array<string, array{outflows: string, inflows: string, result: string}>, totals: array<string, string>, unmapped: list<array<string, string>>}
     */
    public function cashReport(int $year, ?array $costCenterIds = null): array
    {
        $current = $this->cashFigures($year, $costCenterIds);
        $previous = $this->cashFigures($year - 1, $costCenterIds);
        $lines = [];

        foreach (ThirdSectorScheme::CashReport as $code => $label) {
            $lines[] = [
                'code' => $code,
                'label' => $label,
                'section' => ThirdSectorScheme::section($code),
                'side' => ThirdSectorScheme::isRevenue($code) ? 'inflow' : 'outflow',
                'current' => $current['lines'][$code] ?? '0.00',
                'previous' => $previous['lines'][$code] ?? '0.00',
            ];
        }

        return ['year' => $year, 'lines' => $lines, 'sections' => $current['sections'], 'totals' => $current['totals'], 'unmapped' => $current['unmapped']];
    }

    /**
     * Revenue of the year by mapping: total, commercial and non-commercial, and the secondary-activities section.
     *
     * @return array{total: string, commercial: string, non_commercial: string, secondary: string, costs: string}
     */
    public function revenueBreakdown(int $year): array
    {
        $figures = $this->managementFigures($year, null);

        return [
            'total' => $figures['totals']['revenues'],
            'commercial' => $figures['commercial'],
            'non_commercial' => Money::sub($figures['totals']['revenues'], $figures['commercial']),
            'secondary' => $figures['sections']['B']['revenues'],
            'costs' => $figures['totals']['costs'],
        ];
    }

    /**
     * @param  list<string>|null  $costCenterIds
     * @return array{lines: array<string, string>, sections: array<string, array{costs: string, revenues: string, result: string}>, totals: array<string, string>, commercial: string, unmapped: list<array<string, string>>}
     */
    private function managementFigures(int $year, ?array $costCenterIds): array
    {
        [$from, $to] = $this->period($year);
        $rows = $this->postedLines()
            ->whereBetween('journal_entries.posting_date', [$from, $to])
            ->when($costCenterIds !== null, static fn (Builder $query) => $query->whereIn('journal_lines.cost_center_id', $costCenterIds))
            ->groupBy('journal_lines.account_id', 'journal_lines.cost_center_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->get(['journal_lines.account_id', 'journal_lines.cost_center_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', DB::raw('SUM(journal_lines.debit_amount) as debit'), DB::raw('SUM(journal_lines.credit_amount) as credit')]);

        $mappings = $this->mappings('B');
        $lines = [];
        $commercial = '0.00';
        $unmapped = [];

        foreach ($rows as $row) {
            $mapping = $this->resolve($mappings, (string) $row->account_id, $row->cost_center_id === null ? null : (string) $row->cost_center_id);
            $net = Money::sub(Money::normalize((string) $row->debit), Money::normalize((string) $row->credit));

            if ($mapping === null) {
                if (in_array($row->account_type, self::ProfitAndLossTypes, true) && Money::compare($net, '0') !== 0) {
                    $unmapped[] = ['account_id' => (string) $row->account_id, 'account_code' => (string) $row->account_code, 'name' => (string) $row->name, 'amount' => $net];
                }

                continue;
            }

            $amount = ThirdSectorScheme::isRevenue($mapping->line_code) ? Money::sub('0', $net) : $net;
            $lines[$mapping->line_code] = Money::add($lines[$mapping->line_code] ?? '0.00', $amount);

            if ($mapping->is_commercial && ThirdSectorScheme::isRevenue($mapping->line_code)) {
                $commercial = Money::add($commercial, $amount);
            }
        }

        $sections = [];

        foreach (array_keys(ThirdSectorScheme::ManagementSections) as $letter) {
            $costs = $this->sum($lines, "B-C.{$letter}.");
            $revenues = $this->sum($lines, "B-R.{$letter}.");
            $sections[$letter] = ['costs' => $costs, 'revenues' => $revenues, 'result' => Money::sub($revenues, $costs)];
        }

        $costs = $this->sum($lines, 'B-C.', ['B-C.T']);
        $revenues = $this->sum($lines, 'B-R.');
        $beforeTaxes = Money::sub($revenues, $costs);
        $taxes = $lines['B-C.T'] ?? '0.00';

        return [
            'lines' => $lines,
            'sections' => $sections,
            'totals' => ['costs' => $costs, 'revenues' => $revenues, 'result_before_taxes' => $beforeTaxes, 'taxes' => $taxes, 'result' => Money::sub($beforeTaxes, $taxes)],
            'commercial' => $commercial,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * @return array{lines: array<string, string>, totals: array<string, string>, unmapped: list<array<string, string>>}
     */
    private function balanceFigures(int $year): array
    {
        [$from, $to] = $this->period($year);
        $rows = $this->postedLines()
            ->where('journal_entries.posting_date', '<=', $to)
            ->groupBy('journal_lines.account_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->get(['journal_lines.account_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'chart_of_accounts.account_type', DB::raw('SUM(journal_lines.debit_amount) as debit'), DB::raw('SUM(journal_lines.credit_amount) as credit')]);

        $mappings = $this->mappings('A');
        $lines = [];
        $unmapped = [];

        foreach ($rows as $row) {
            if (in_array($row->account_type, self::ProfitAndLossTypes, true)) {
                continue;
            }

            $net = Money::sub(Money::normalize((string) $row->debit), Money::normalize((string) $row->credit));
            $mapping = $this->resolve($mappings, (string) $row->account_id, null);

            if ($mapping === null) {
                if (Money::compare($net, '0') !== 0) {
                    $unmapped[] = ['account_id' => (string) $row->account_id, 'account_code' => (string) $row->account_code, 'name' => (string) $row->name, 'amount' => $net];
                }

                continue;
            }

            $amount = ThirdSectorScheme::isAsset($mapping->line_code) ? $net : Money::sub('0', $net);
            $lines[$mapping->line_code] = Money::add($lines[$mapping->line_code] ?? '0.00', $amount);
        }

        $result = $this->profitAndLoss($from, $to);
        $earlier = $this->profitAndLoss(null, CarbonImmutable::parse($from)->subDay()->toDateString());
        $lines[ThirdSectorScheme::ResultLine] = $result;

        if (Money::compare($earlier, '0') !== 0) {
            $lines[ThirdSectorScheme::RetainedResultsLine] = Money::add($lines[ThirdSectorScheme::RetainedResultsLine] ?? '0.00', $earlier);
        }

        $assets = $this->sum($lines, 'A-AS.');
        $liabilities = $this->sum($lines, 'A-LI.');

        return [
            'lines' => $lines,
            'totals' => ['assets' => $assets, 'liabilities_and_equity' => $liabilities, 'result' => $result, 'unclosed_earlier_results' => $earlier, 'difference' => Money::sub($assets, $liabilities)],
            'unmapped' => $unmapped,
        ];
    }

    /**
     * Cash movements: every posted entry that touches a cash or bank account (the accounts mapped to the liquidity lines
     * of the balance sheet) is split over the cash report through the mapping of its other accounts.
     *
     * @param  list<string>|null  $costCenterIds
     * @return array{lines: array<string, string>, sections: array<string, array{outflows: string, inflows: string, result: string}>, totals: array<string, string>, unmapped: list<array<string, string>>}
     */
    private function cashFigures(int $year, ?array $costCenterIds): array
    {
        [$from, $to] = $this->period($year);
        $liquidity = StatementMapping::query()->where('scheme', 'A')->whereIn('line_code', ThirdSectorScheme::LiquidityLines)->pluck('account_id')->map(static fn (mixed $id): string => (string) $id)->unique()->values()->all();
        $mappings = $this->mappings('D');
        $lines = [];
        $unmapped = [];

        if ($liquidity !== []) {
            $entryIds = $this->postedLines()->whereBetween('journal_entries.posting_date', [$from, $to])->whereIn('journal_lines.account_id', $liquidity)->distinct()->pluck('journal_entries.id');
            $rows = $this->postedLines()->whereIn('journal_entries.id', $entryIds)->whereNotIn('journal_lines.account_id', $liquidity)
                ->when($costCenterIds !== null, static fn (Builder $query) => $query->whereIn('journal_lines.cost_center_id', $costCenterIds))
                ->get(['journal_lines.account_id', 'journal_lines.cost_center_id', 'chart_of_accounts.account_code', 'chart_of_accounts.name', 'journal_lines.debit_amount', 'journal_lines.credit_amount']);

            foreach ($rows as $row) {
                $net = Money::sub(Money::normalize((string) $row->debit_amount), Money::normalize((string) $row->credit_amount));
                $mapping = $this->resolve($mappings, (string) $row->account_id, $row->cost_center_id === null ? null : (string) $row->cost_center_id);

                if ($mapping === null) {
                    $unmapped[(string) $row->account_id] ??= ['account_id' => (string) $row->account_id, 'account_code' => (string) $row->account_code, 'name' => (string) $row->name, 'amount' => '0.00'];
                    $unmapped[(string) $row->account_id]['amount'] = Money::add($unmapped[(string) $row->account_id]['amount'], $net);

                    continue;
                }

                $amount = ThirdSectorScheme::isRevenue($mapping->line_code) ? Money::sub('0', $net) : $net;
                $lines[$mapping->line_code] = Money::add($lines[$mapping->line_code] ?? '0.00', $amount);
            }
        }

        $sections = [];

        foreach ([...array_keys(ThirdSectorScheme::ManagementSections), 'INV'] as $letter) {
            $outflows = $this->sum($lines, "D-O.{$letter}.");
            $inflows = $this->sum($lines, "D-I.{$letter}.");
            $sections[$letter] = ['outflows' => $outflows, 'inflows' => $inflows, 'result' => Money::sub($inflows, $outflows)];
        }

        $managementOut = $this->sum($lines, 'D-O.', ['D-O.T'], 'D-O.INV.');
        $managementIn = $this->sum($lines, 'D-I.', [], 'D-I.INV.');
        $taxes = $lines['D-O.T'] ?? '0.00';
        $management = Money::sub(Money::sub($managementIn, $managementOut), $taxes);
        $investments = $sections['INV']['result'];
        $opening = $this->liquidityBalance($liquidity, CarbonImmutable::parse($from)->subDay()->toDateString());
        $closing = $this->liquidityBalance($liquidity, $to);

        return [
            'lines' => $lines,
            'sections' => $sections,
            'totals' => [
                'inflows' => $managementIn,
                'outflows' => $managementOut,
                'taxes' => $taxes,
                'management_result' => $management,
                'investment_result' => $investments,
                'overall_result' => Money::add($management, $investments),
                'opening_cash' => $opening,
                'closing_cash' => $closing,
                'reconciliation_difference' => $costCenterIds === null ? Money::sub(Money::add($opening, Money::add($management, $investments)), $closing) : '0.00',
            ],
            'unmapped' => array_values($unmapped),
        ];
    }

    /**
     * @param  list<string>  $accounts
     */
    private function liquidityBalance(array $accounts, string $until): string
    {
        if ($accounts === []) {
            return '0.00';
        }

        $row = $this->postedLines()->whereIn('journal_lines.account_id', $accounts)->where('journal_entries.posting_date', '<=', $until)
            ->first([DB::raw('COALESCE(SUM(journal_lines.debit_amount), 0) as debit'), DB::raw('COALESCE(SUM(journal_lines.credit_amount), 0) as credit')]);

        return Money::sub(Money::normalize((string) $row?->debit), Money::normalize((string) $row?->credit));
    }

    /**
     * Revenues minus costs of the accounts of profit and loss in a period, from the ledger itself.
     */
    private function profitAndLoss(?string $from, string $to): string
    {
        $row = $this->postedLines()
            ->whereIn('chart_of_accounts.account_type', self::ProfitAndLossTypes)
            ->when($from !== null, static fn (Builder $query) => $query->where('journal_entries.posting_date', '>=', $from))
            ->where('journal_entries.posting_date', '<=', $to)
            ->first([DB::raw('COALESCE(SUM(journal_lines.debit_amount), 0) as debit'), DB::raw('COALESCE(SUM(journal_lines.credit_amount), 0) as credit')]);

        return Money::sub(Money::normalize((string) $row?->credit), Money::normalize((string) $row?->debit));
    }

    private function postedLines(): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts', 'journal_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('journal_entries.tenant_id', $this->tenantContext->getTenantId())
            ->where('journal_entries.status', 'posted')
            ->where('journal_entries.ledger_type', 'statutory')
            ->where('journal_entries.journal_type', '<>', 'closing')
            ->whereNull('journal_entries.deleted_at')
            ->whereNull('journal_lines.deleted_at');
    }

    /**
     * @return Collection<string, Collection<int, StatementMapping>>
     */
    private function mappings(string $scheme): Collection
    {
        return StatementMapping::query()->where('scheme', $scheme)->get()->groupBy('account_id');
    }

    /**
     * The mapping for the cost centre of the line wins over the one valid for any cost centre.
     *
     * @param  Collection<string, Collection<int, StatementMapping>>  $mappings
     */
    private function resolve(Collection $mappings, string $accountId, ?string $costCenterId): ?StatementMapping
    {
        $candidates = $mappings->get($accountId);

        if ($candidates === null) {
            return null;
        }

        return $candidates->first(static fn (StatementMapping $mapping): bool => $costCenterId !== null && $mapping->cost_center_id === $costCenterId)
            ?? $candidates->first(static fn (StatementMapping $mapping): bool => $mapping->cost_center_id === null);
    }

    /**
     * @param  array<string, string>  $lines
     * @param  list<string>  $except
     */
    private function sum(array $lines, string $prefix, array $except = [], ?string $excludePrefix = null): string
    {
        $total = '0.00';

        foreach ($lines as $code => $amount) {
            if (str_starts_with($code, $prefix) && ! in_array($code, $except, true) && ($excludePrefix === null || ! str_starts_with($code, $excludePrefix))) {
                $total = Money::add($total, $amount);
            }
        }

        return $total;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function period(int $year): array
    {
        return [CarbonImmutable::create($year)->startOfYear()->toDateString(), CarbonImmutable::create($year)->endOfYear()->toDateString()];
    }
}
