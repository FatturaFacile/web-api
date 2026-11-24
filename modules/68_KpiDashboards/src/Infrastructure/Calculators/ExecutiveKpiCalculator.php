<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Calculators;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\IntercompanyEliminator;
use Modules\KpiDashboards\Infrastructure\Services\KpiContext;
use Modules\KpiDashboards\Infrastructure\Services\LedgerAggregator;
use stdClass;

/**
 * CEO cockpit: revenue against budget, EBITDA, net financial position, order backlog and headcount.
 */
final class ExecutiveKpiCalculator implements KpiCalculator
{
    /** Orders in these states carry no open obligation towards the customer. */
    private const array ClosedOrderStatuses = ['draft', 'cancelled', 'rejected', 'closed', 'completed'];

    public function __construct(
        private readonly LedgerAggregator $ledger,
        private readonly IntercompanyEliminator $eliminator,
    ) {}

    public function supports(): array
    {
        return [KpiCode::RevenueYtdVsBudget, KpiCode::Ebitda, KpiCode::NetFinancialPosition, KpiCode::OrderBacklog, KpiCode::HeadcountFte];
    }

    public function headline(KpiCode $kpi, CockpitFilter $filter): ?float
    {
        return match ($kpi) {
            KpiCode::RevenueYtdVsBudget => $this->revenue($filter),
            KpiCode::Ebitda => $this->ebitdaParts($filter)['ebitda'],
            KpiCode::NetFinancialPosition => $this->netFinancialPosition($filter),
            KpiCode::OrderBacklog => $this->backlog($filter),
            KpiCode::HeadcountFte => $this->fte($filter),
            default => null,
        };
    }

    public function payload(KpiCode $kpi, KpiContext $context): array
    {
        $filter = $context->filter;

        return match ($kpi) {
            KpiCode::RevenueYtdVsBudget => $this->revenuePayload($context),
            KpiCode::Ebitda => $this->ebitdaPayload($context),
            KpiCode::NetFinancialPosition => ['unit' => 'currency', 'breakdown' => [
                'liquidity' => $context->money($this->cash($filter)),
                'financial_debt' => $context->money($this->financialDebt($filter)),
            ]],
            KpiCode::OrderBacklog => $this->backlogPayload($context),
            KpiCode::HeadcountFte => ['unit' => 'fte', 'breakdown' => ['headcount' => $this->headcount($filter), 'by_company' => $this->fteByCompany($filter)]],
            default => [],
        };
    }

    private function revenue(CockpitFilter $filter): float
    {
        $revenue = $this->ledger->net($filter, ['revenue'], $filter->from, $filter->to, null, true);

        return round($revenue - $this->eliminator->eliminations($filter)['revenue'], 2);
    }

    /**
     * @return array{revenue: float, cogs: float, opex: float, ebitda: float, margin: float|null}
     */
    private function ebitdaParts(CockpitFilter $filter): array
    {
        $eliminated = $this->eliminator->eliminations($filter);
        $revenue = $this->ledger->net($filter, ['revenue'], $filter->from, $filter->to, null, true) - $eliminated['revenue'];
        $cogs = $this->ledger->net($filter, ['cogs'], $filter->from, $filter->to) - $eliminated['cogs'];
        // Depreciation and other non-cash expenses sit below EBITDA.
        $expenses = $this->ledger->net($filter, ['expense'], $filter->from, $filter->to);
        $nonCash = $this->ledger->net($filter, ['expense'], $filter->from, $filter->to, 'non_cash_expense');
        $opex = $expenses - $nonCash - $eliminated['opex'];
        $ebitda = $revenue - $cogs - $opex;

        return [
            'revenue' => round($revenue, 2), 'cogs' => round($cogs, 2), 'opex' => round($opex, 2),
            'ebitda' => round($ebitda, 2), 'margin' => $revenue > 0 ? round($ebitda / $revenue * 100, 2) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ebitdaPayload(KpiContext $context): array
    {
        $parts = $this->ebitdaParts($context->filter);

        return [
            'unit' => 'currency',
            'margin_percent' => $parts['margin'],
            'waterfall' => [
                ['step' => 'revenue', 'amount' => $context->money($parts['revenue'])],
                ['step' => 'cogs', 'amount' => $context->money(-$parts['cogs'])],
                ['step' => 'opex', 'amount' => $context->money(-$parts['opex'])],
                ['step' => 'ebitda', 'amount' => $context->money($parts['ebitda']), 'is_total' => true],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revenuePayload(KpiContext $context): array
    {
        $filter = $context->filter;
        $months = $this->ledger->monthlyIncome($filter, $filter->from, $filter->to);
        $budget = $this->monthlyBudget($filter);
        $series = [];
        $budgetTotal = 0.0;

        foreach ($months as $month => $amounts) {
            $planned = $budget[$month] ?? 0.0;
            $budgetTotal += $planned;
            $series[] = ['period' => $month, 'actual' => $context->money($amounts['revenue']), 'budget' => $context->money($planned)];
        }

        $actual = $this->revenue($filter);

        return [
            'unit' => 'currency',
            'series' => $series,
            'budget_value' => $context->money($budgetTotal),
            'variance' => $context->money($actual - $budgetTotal),
            'variance_percent' => $budgetTotal > 0 ? round(($actual - $budgetTotal) / $budgetTotal * 100, 2) : null,
        ];
    }

    /**
     * Budgeted revenue per month ('Y-m'): the budget period numbers count months from the start of the fiscal year.
     *
     * @return array<string, float>
     */
    private function monthlyBudget(CockpitFilter $filter): array
    {
        $rows = DB::table('gl_budget_lines')
            ->join('gl_budgets', 'gl_budget_lines.budget_id', '=', 'gl_budgets.id')
            ->join('fiscal_years', 'gl_budgets.fiscal_year_id', '=', 'fiscal_years.id')
            ->join('chart_of_accounts', 'gl_budget_lines.account_id', '=', 'chart_of_accounts.id')
            ->where('gl_budget_lines.tenant_id', $filter->tenantId)
            ->where('chart_of_accounts.account_type', 'revenue')
            ->whereNotIn('gl_budgets.status', ['draft', 'rejected', 'archived'])
            ->whereNull('gl_budget_lines.deleted_at')
            ->when($filter->companyIds !== [], static fn ($query) => $query->whereIn('gl_budgets.company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn ($query) => $query->whereIn('gl_budget_lines.cost_center_id', $filter->costCenterIds))
            ->get(['fiscal_years.start_date', 'gl_budget_lines.period_number', 'gl_budget_lines.amount']);

        $months = [];

        foreach ($rows as $row) {
            $month = CarbonImmutable::parse($row->start_date)->startOfMonth()->addMonths(((int) $row->period_number) - 1)->format('Y-m');
            $months[$month] = ($months[$month] ?? 0.0) + (float) $row->amount;
        }

        return $months;
    }

    private function cash(CockpitFilter $filter): float
    {
        return $this->ledger->net($filter, ['asset'], null, $filter->to, 'cash');
    }

    private function financialDebt(CockpitFilter $filter): float
    {
        return $this->ledger->net($filter, ['liability'], null, $filter->to, 'non_current_liability', true);
    }

    private function netFinancialPosition(CockpitFilter $filter): float
    {
        return round($this->cash($filter) - $this->financialDebt($filter), 2);
    }

    /**
     * Value of the confirmed order lines not yet delivered or cancelled.
     */
    private function backlog(CockpitFilter $filter): float
    {
        return round($this->backlogRows($filter)->sum('remaining_value'), 2);
    }

    /**
     * @return Collection<int, covariant object>
     */
    private function backlogRows(CockpitFilter $filter): Collection
    {
        return DB::table('sales_order_items')
            ->join('sales_orders', 'sales_order_items.sales_order_id', '=', 'sales_orders.id')
            ->where('sales_orders.tenant_id', $filter->tenantId)
            ->whereNull('sales_orders.deleted_at')
            ->whereNotIn('sales_orders.status', self::ClosedOrderStatuses)
            ->whereDate('sales_orders.date', '<=', $filter->to->toDateString())
            ->get(['sales_orders.date', 'sales_order_items.quantity', 'sales_order_items.quantity_delivered', 'sales_order_items.quantity_cancelled', 'sales_order_items.unit_price', 'sales_order_items.discount_percentage'])
            ->map(static function (object $row): stdClass {
                $open = max(0.0, (float) $row->quantity - (float) $row->quantity_delivered - (float) $row->quantity_cancelled);

                return (object) ['date' => (string) $row->date, 'remaining_value' => $open * (float) $row->unit_price * (1 - (float) $row->discount_percentage / 100)];
            })
            ->filter(static fn (object $row): bool => $row->remaining_value > 0)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function backlogPayload(KpiContext $context): array
    {
        $byMonth = [];

        foreach ($this->backlogRows($context->filter) as $row) {
            $month = CarbonImmutable::parse($row->date)->format('Y-m');
            $byMonth[$month] = ($byMonth[$month] ?? 0.0) + $row->remaining_value;
        }

        ksort($byMonth);

        return [
            'unit' => 'currency',
            'series' => array_map(fn (string $month, float $value): array => ['period' => $month, 'value' => $context->money($value)], array_keys($byMonth), array_values($byMonth)),
        ];
    }

    private function activeEmployees(CockpitFilter $filter): Builder
    {
        return DB::table('hr_employees')
            ->where('tenant_id', $filter->tenantId)
            ->whereNull('deleted_at')
            ->whereDate('hire_date', '<=', $filter->to->toDateString())
            ->where(static fn ($query) => $query->whereNull('termination_date')->orWhereDate('termination_date', '>', $filter->to->toDateString()))
            ->when($filter->companyIds !== [], static fn ($query) => $query->whereIn('company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn ($query) => $query->whereIn('cost_center_id', $filter->costCenterIds));
    }

    private function fte(CockpitFilter $filter): float
    {
        return round((float) $this->activeEmployees($filter)->sum('fte_ratio'), 2);
    }

    private function headcount(CockpitFilter $filter): int
    {
        return $this->activeEmployees($filter)->count();
    }

    /**
     * @return list<array{company_id: string|null, fte: float}>
     */
    private function fteByCompany(CockpitFilter $filter): array
    {
        return $this->activeEmployees($filter)
            ->selectRaw('company_id, SUM(fte_ratio) as fte')->groupBy('company_id')->get()
            ->map(static fn (object $row): array => ['company_id' => $row->company_id !== null ? (string) $row->company_id : null, 'fte' => round((float) $row->fte, 2)])
            ->all();
    }
}
