<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\KpiDashboards\Application\Queries\DrillDownKpiToSourceDocumentsQuery;
use Modules\KpiDashboards\Domain\Enums\KpiCode;
use Modules\KpiDashboards\Domain\Exceptions\KpiAccessDeniedException;
use Modules\KpiDashboards\Domain\ValueObjects\CockpitFilter;
use Modules\KpiDashboards\Infrastructure\Services\KpiAccess;
use Modules\KpiDashboards\Infrastructure\Services\LedgerAggregator;

/**
 * Resolves a KPI segment into the paginated list of documents behind it. The same access rules as the cockpit apply
 * (role of the KPI, legal entities and cost centers of the member), and compensation fields are never returned without the
 * `kpi.hr.compensation` grant. The result carries the breadcrumb and the deep link into the ERP module with the filters.
 */
final class DrillDownKpiToSourceDocumentsHandler
{
    private const int ExportLimit = 5000;

    private const array ClosedOrderStatuses = ['draft', 'cancelled', 'rejected', 'closed', 'completed'];

    public function __construct(private readonly LedgerAggregator $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(DrillDownKpiToSourceDocumentsQuery $query): array
    {
        $tenantId = $query->filter->tenantId;
        $access = KpiAccess::forUser($query->user, $tenantId);

        if (! $access->canSeeKpi($query->kpi)) {
            throw new KpiAccessDeniedException("Your role may not drill into the KPI [{$query->kpi->value}].");
        }

        $filter = $access->restrict($query->filter, $query->filter->eliminateIntercompany);
        $perPage = $query->export ? self::ExportLimit : max(1, min(100, $query->perPage));
        $page = $query->export ? 1 : max(1, $query->page);
        $type = $query->kpi->sourceDocumentType();

        $builder = match ($type) {
            'journal_entries' => $this->journalEntries($query->kpi, $filter, $query->segment),
            'invoices' => $this->invoices($query->kpi, $filter, $query->segment),
            'passive_invoices' => $this->passiveInvoices($filter, $query->segment),
            'sales_orders' => $this->salesOrders($query->kpi, $filter),
            'opportunities' => $this->opportunities($query->kpi, $filter, $query->segment),
            'employees' => $this->employees($query->kpi, $filter),
            'leave_requests' => $this->leaveRequests($filter),
            'timesheets' => $this->timesheets($filter, $query->segment),
            default => $this->productionOrders($filter),
        };

        $total = (clone $builder['query'])->count();
        $rows = $builder['query']->forPage($page, $perPage)->get()->map(fn (object $row): array => $this->mask((array) $row, $access))->all();

        return [
            'kpi_code' => $query->kpi->value,
            'document_type' => $type,
            'breadcrumb' => $this->breadcrumb($query),
            'columns' => $builder['columns'],
            'rows' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'deep_link' => ['route' => $builder['route'], 'query' => $this->deepLinkQuery($query)],
        ];
    }

    /**
     * Fields that never leave the server without the compensation grant.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mask(array $row, KpiAccess $access): array
    {
        if ($access->canSeeCompensation()) {
            return $row;
        }

        foreach (['hourly_cost_rate', 'total_labor_cost', 'base_annual_salary'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = null;
            }
        }

        return $row;
    }

    /**
     * @return list<string>
     */
    private function breadcrumb(DrillDownKpiToSourceDocumentsQuery $query): array
    {
        $crumbs = [strtoupper($query->kpi->role()->value), $query->kpi->value];

        foreach ($query->segment as $key => $value) {
            $crumbs[] = "{$key}: {$value}";
        }

        $crumbs[] = 'currency: '.$query->filter->displayCurrency;

        return $crumbs;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function deepLinkQuery(DrillDownKpiToSourceDocumentsQuery $query): array
    {
        $filter = $query->filter;

        return array_filter([
            'from' => $filter->from->toDateString(),
            'to' => $filter->to->toDateString(),
            'company_ids' => $filter->companyIds,
            'cost_center_ids' => $filter->costCenterIds,
            ...$query->segment,
        ], static fn (mixed $value): bool => $value !== []);
    }

    /**
     * Calendar bounds of a 'Y-m' segment inside the filter period, or the whole period.
     *
     * @param  array<string, string>  $segment
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(CockpitFilter $filter, array $segment): array
    {
        if (! isset($segment['period']) || preg_match('/^\d{4}-\d{2}$/', $segment['period']) !== 1) {
            return [$filter->from, $filter->to];
        }

        $month = CarbonImmutable::createFromFormat('Y-m-d', $segment['period'].'-01')->startOfDay();

        return [max($month, $filter->from), min($month->endOfMonth(), $filter->to)];
    }

    /**
     * @param  array<string, string>  $segment
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function journalEntries(KpiCode $kpi, CockpitFilter $filter, array $segment): array
    {
        [$from, $to] = $this->bounds($filter, $segment);
        $scopes = match ($kpi) {
            KpiCode::RevenueYtdVsBudget => [[['revenue'], null]],
            KpiCode::GrossMargin => [[['revenue', 'cogs'], null]],
            KpiCode::Ebitda => [[['revenue', 'cogs', 'expense'], null]],
            KpiCode::NetFinancialPosition => [[['asset'], 'cash'], [['liability'], 'non_current_liability']],
            KpiCode::NetWorkingCapital => [[['asset'], 'inventory']],
            KpiCode::InventoryTurnover => [[['cogs'], null], [['asset'], 'inventory']],
            default => [[['asset'], 'cash']],
        };
        $isBalance = in_array($kpi, [KpiCode::NetFinancialPosition, KpiCode::NetWorkingCapital, KpiCode::CashRunway], true);

        $query = $this->ledger->lines($filter)->whereDate('journal_entries.posting_date', '<=', $to->toDateString())
            ->where(static function (Builder $where) use ($scopes): void {
                foreach ($scopes as [$types, $class]) {
                    $where->orWhere(static function (Builder $scope) use ($types, $class): void {
                        $scope->whereIn('chart_of_accounts.account_type', $types);

                        if ($class !== null) {
                            $scope->where('chart_of_accounts.reporting_class', $class);
                        }
                    });
                }
            })
            ->groupBy('journal_entries.id', 'journal_entries.entry_number', 'journal_entries.posting_date', 'journal_entries.description', 'journal_entries.status', 'journal_entries.company_id')
            ->orderByDesc('journal_entries.posting_date')->orderBy('journal_entries.entry_number')
            ->selectRaw('journal_entries.id as id, journal_entries.entry_number as entry_number, journal_entries.posting_date as posting_date, journal_entries.description as description, journal_entries.status as status, journal_entries.company_id as company_id, COALESCE(SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount)), 0) as debit, COALESCE(SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount)), 0) as credit');

        if (! $isBalance) {
            $query->whereDate('journal_entries.posting_date', '>=', $from->toDateString());
        }

        return ['query' => $this->asCounted($query), 'columns' => ['entry_number', 'posting_date', 'description', 'debit', 'credit', 'status'], 'route' => '/general-ledger/journal-entries'];
    }

    /**
     * Wraps a grouped query so that count() counts groups on every database.
     */
    private function asCounted(Builder $grouped): Builder
    {
        return DB::query()->fromSub($grouped, 'drill')->select('drill.*')->orderByDesc('drill.posting_date')->orderBy('drill.entry_number');
    }

    /**
     * @param  array<string, string>  $segment
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function invoices(KpiCode $kpi, CockpitFilter $filter, array $segment): array
    {
        if (($segment['side'] ?? null) === 'payable') {
            return $this->passiveInvoices($filter, $segment);
        }

        [$from, $to] = $this->bounds($filter, $segment);
        $query = DB::table('invoices')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->where('invoice_type', '!=', 'proforma')
            ->whereDate('date', '<=', $to->toDateString())
            ->select(['id', 'invoice_number', 'date', 'due_date', 'contact_id', 'currency', 'total_net', 'total_tax', 'total_gross', 'total_paid', 'payment_status', 'jurisdiction'])
            ->orderByDesc('date');

        if ($kpi === KpiCode::ArApAging || $kpi === KpiCode::Dso) {
            $query->where('payment_status', '!=', 'paid')->whereColumn('total_gross', '>', 'total_paid');
            $this->bucket($query, $filter, $segment['bucket'] ?? null);
        } else {
            $query->whereDate('date', '>=', $from->toDateString());
        }

        if (isset($segment['contact_id'])) {
            $query->where('contact_id', $segment['contact_id']);
        }

        if (isset($segment['product_id'])) {
            $query->whereExists(static fn (Builder $exists) => $exists->select(DB::raw('1'))->from('invoice_items')->whereColumn('invoice_items.invoice_id', 'invoices.id')->where('invoice_items.product_id', $segment['product_id']));
        }

        return ['query' => $query, 'columns' => ['invoice_number', 'date', 'due_date', 'contact_id', 'currency', 'total_net', 'total_gross', 'total_paid', 'payment_status'], 'route' => '/invoicing/invoices'];
    }

    /**
     * @param  array<string, string>  $segment
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function passiveInvoices(CockpitFilter $filter, array $segment): array
    {
        $query = DB::table('passive_invoices')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereDate('date', '<=', $filter->to->toDateString())
            ->where('payment_status', '!=', 'paid')->whereColumn('total_amount', '>', 'total_paid')
            ->select(['id', 'invoice_number', 'date', 'due_date', 'supplier_id', 'currency', 'total_net', 'total_amount', 'total_paid', 'payment_status'])
            ->orderByDesc('date');
        $this->bucket($query, $filter, $segment['bucket'] ?? null);

        return ['query' => $query, 'columns' => ['invoice_number', 'date', 'due_date', 'supplier_id', 'currency', 'total_amount', 'total_paid', 'payment_status'], 'route' => '/procurement/vendor-invoices'];
    }

    /**
     * Restricts open documents to an aging bucket: days past due at the end of the period (a missing due date is the issue date).
     */
    private function bucket(Builder $query, CockpitFilter $filter, ?string $bucket): void
    {
        if ($bucket === null) {
            return;
        }

        $to = $filter->to->startOfDay();
        $due = 'COALESCE(due_date, date)';
        $day = static fn (int $daysPast): string => $to->subDays($daysPast)->toDateString();

        match ($bucket) {
            'current' => $query->whereRaw("{$due} >= ?", [$day(0)]),
            '1_30' => $query->whereRaw("{$due} < ? AND {$due} >= ?", [$day(0), $day(30)]),
            '31_60' => $query->whereRaw("{$due} < ? AND {$due} >= ?", [$day(30), $day(60)]),
            '61_90' => $query->whereRaw("{$due} < ? AND {$due} >= ?", [$day(60), $day(90)]),
            default => $query->whereRaw("{$due} < ?", [$day(90)]),
        };
    }

    /**
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function salesOrders(KpiCode $kpi, CockpitFilter $filter): array
    {
        $query = DB::table('sales_orders')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->select(['id', 'order_number', 'date', 'requested_delivery_date', 'contact_id', 'currency', 'total_net', 'status', 'delivered_percentage'])
            ->orderByDesc('date');

        if ($kpi === KpiCode::Otif) {
            $query->whereNotIn('status', ['draft', 'cancelled', 'rejected'])
                ->whereDate('requested_delivery_date', '>=', $filter->from->toDateString())->whereDate('requested_delivery_date', '<=', $filter->to->toDateString());
        } else {
            $query->whereNotIn('status', self::ClosedOrderStatuses)->whereDate('date', '<=', $filter->to->toDateString());
        }

        return ['query' => $query, 'columns' => ['order_number', 'date', 'requested_delivery_date', 'contact_id', 'total_net', 'status', 'delivered_percentage'], 'route' => '/sales/orders'];
    }

    /**
     * @param  array<string, string>  $segment
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function opportunities(KpiCode $kpi, CockpitFilter $filter, array $segment): array
    {
        $query = DB::table('crm_opportunities')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->select(['id', 'opportunity_number', 'name', 'stage_id', 'amount', 'currency', 'probability', 'status', 'expected_close_date', 'actual_close_date'])
            ->orderByDesc('amount');

        if ($kpi === KpiCode::WinRate) {
            $query->whereIn('status', isset($segment['outcome']) ? [$segment['outcome']] : ['won', 'lost'])
                ->whereDate('actual_close_date', '>=', $filter->from->toDateString())->whereDate('actual_close_date', '<=', $filter->to->toDateString());
        } else {
            $query->whereNotIn('status', ['won', 'lost']);

            if (isset($segment['stage_id'])) {
                $query->where('stage_id', $segment['stage_id']);
            }
        }

        return ['query' => $query, 'columns' => ['opportunity_number', 'name', 'stage_id', 'amount', 'probability', 'status', 'expected_close_date'], 'route' => '/crm/opportunities'];
    }

    /**
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function employees(KpiCode $kpi, CockpitFilter $filter): array
    {
        $query = DB::table('hr_employees')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->when($filter->companyIds !== [], static fn (Builder $where) => $where->whereIn('company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn (Builder $where) => $where->whereIn('cost_center_id', $filter->costCenterIds))
            ->select(['id', 'employee_number', 'full_name', 'company_id', 'department_id', 'team_id', 'fte_ratio', 'hire_date', 'termination_date', 'employment_status'])
            ->orderBy('employee_number');

        if ($kpi === KpiCode::HeadcountTurnover) {
            $query->whereDate('termination_date', '>=', $filter->from->toDateString())->whereDate('termination_date', '<=', $filter->to->toDateString());
        } else {
            $query->whereDate('hire_date', '<=', $filter->to->toDateString())
                ->where(static fn (Builder $where) => $where->whereNull('termination_date')->orWhereDate('termination_date', '>', $filter->to->toDateString()));
        }

        return ['query' => $query, 'columns' => ['employee_number', 'full_name', 'department_id', 'fte_ratio', 'hire_date', 'termination_date'], 'route' => '/hr/employees'];
    }

    /**
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function leaveRequests(CockpitFilter $filter): array
    {
        $query = DB::table('hr_leave_requests')
            ->join('hr_leave_types', 'hr_leave_requests.leave_type_id', '=', 'hr_leave_types.id')
            ->where('hr_leave_requests.tenant_id', $filter->tenantId)->whereNull('hr_leave_requests.deleted_at')
            ->where('hr_leave_requests.status', 'approved')->whereIn('hr_leave_types.category', ['sick', 'accident'])
            ->whereDate('hr_leave_requests.start_date', '>=', $filter->from->toDateString())->whereDate('hr_leave_requests.start_date', '<=', $filter->to->toDateString())
            ->when($filter->companyIds !== [], static fn (Builder $where) => $where->whereIn('hr_leave_requests.company_id', $filter->companyIds))
            ->select(['hr_leave_requests.id as id', 'hr_leave_requests.request_number as request_number', 'hr_leave_requests.employee_id as employee_id', 'hr_leave_types.category as category', 'hr_leave_requests.start_date as start_date', 'hr_leave_requests.end_date as end_date', 'hr_leave_requests.total_hours as total_hours'])
            ->orderByDesc('hr_leave_requests.start_date');

        return ['query' => $query, 'columns' => ['request_number', 'employee_id', 'category', 'start_date', 'end_date', 'total_hours'], 'route' => '/hr/leave-requests'];
    }

    /**
     * @param  array<string, string>  $segment
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function timesheets(CockpitFilter $filter, array $segment): array
    {
        [$from, $to] = $this->bounds($filter, $segment);
        $query = DB::table('hr_timesheets')->where('hr_timesheets.tenant_id', $filter->tenantId)->whereNull('hr_timesheets.deleted_at')
            ->where('hr_timesheets.status', 'approved')
            ->whereDate('hr_timesheets.work_date', '>=', $from->toDateString())->whereDate('hr_timesheets.work_date', '<=', $to->toDateString())
            ->when($filter->companyIds !== [], static fn (Builder $where) => $where->whereIn('hr_timesheets.company_id', $filter->companyIds))
            ->when($filter->costCenterIds !== [], static fn (Builder $where) => $where->whereIn('hr_timesheets.cost_center_id', $filter->costCenterIds))
            ->select(['hr_timesheets.id as id', 'hr_timesheets.timesheet_number as timesheet_number', 'hr_timesheets.employee_id as employee_id', 'hr_timesheets.work_date as work_date', 'hr_timesheets.regular_hours as regular_hours', 'hr_timesheets.overtime_hours as overtime_hours', 'hr_timesheets.billable_hours as billable_hours', 'hr_timesheets.total_hours as total_hours', 'hr_timesheets.hourly_cost_rate as hourly_cost_rate', 'hr_timesheets.total_labor_cost as total_labor_cost'])
            ->orderByDesc('hr_timesheets.work_date');

        if (isset($segment['team_id'])) {
            $query->whereIn('hr_timesheets.employee_id', DB::table('hr_employees')->where('tenant_id', $filter->tenantId)->where('team_id', $segment['team_id'])->select('id'));
        }

        return ['query' => $query, 'columns' => ['timesheet_number', 'employee_id', 'work_date', 'regular_hours', 'overtime_hours', 'billable_hours', 'hourly_cost_rate', 'total_labor_cost'], 'route' => '/hr/timesheets'];
    }

    /**
     * @return array{query: Builder, columns: list<string>, route: string}
     */
    private function productionOrders(CockpitFilter $filter): array
    {
        $query = DB::table('mfg_production_orders')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->whereNotNull('actual_end_date')
            ->whereDate('actual_end_date', '>=', $filter->from->toDateString())->whereDate('actual_end_date', '<=', $filter->to->toDateString())
            ->select(['id', 'order_number', 'status', 'target_quantity', 'completed_quantity', 'scrapped_quantity', 'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date'])
            ->orderByDesc('actual_end_date');

        return ['query' => $query, 'columns' => ['order_number', 'status', 'target_quantity', 'completed_quantity', 'scrapped_quantity', 'actual_end_date'], 'route' => '/manufacturing/production-orders'];
    }
}
