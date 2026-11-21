<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Domain\Enums;

/**
 * The KPI catalog. A flow metric (P&L, activity over the period) converts at the average rate of the period, a stock
 * metric (balance sheet position at the end of the period) at the closing spot rate.
 */
enum KpiCode: string
{
    case RevenueYtdVsBudget = 'revenue_ytd_vs_budget';
    case Ebitda = 'ebitda';
    case NetFinancialPosition = 'net_financial_position';
    case OrderBacklog = 'order_backlog';
    case HeadcountFte = 'headcount_fte';
    case Dso = 'dso';
    case Dpo = 'dpo';
    case NetWorkingCapital = 'net_working_capital';
    case CashRunway = 'cash_runway';
    case ArApAging = 'ar_ap_aging';
    case VatLiability = 'vat_liability';
    case WeightedPipeline = 'weighted_pipeline';
    case WinRate = 'win_rate';
    case GrossMargin = 'gross_margin';
    case TopCustomers = 'top_customers';
    case TopProducts = 'top_products';
    case InventoryTurnover = 'inventory_turnover';
    case Otif = 'otif';
    case Oee = 'oee';
    case ScrapRate = 'scrap_rate';
    case HeadcountTurnover = 'headcount_turnover';
    case Absenteeism = 'absenteeism';
    case OvertimeTrend = 'overtime_trend';
    case BillableUtilization = 'billable_utilization';

    public function role(): RoleCode
    {
        return match ($this) {
            self::RevenueYtdVsBudget, self::Ebitda, self::NetFinancialPosition, self::OrderBacklog, self::HeadcountFte => RoleCode::Ceo,
            self::Dso, self::Dpo, self::NetWorkingCapital, self::CashRunway, self::ArApAging, self::VatLiability => RoleCode::Cfo,
            self::WeightedPipeline, self::WinRate, self::GrossMargin, self::TopCustomers, self::TopProducts => RoleCode::Sales,
            self::InventoryTurnover, self::Otif, self::Oee, self::ScrapRate => RoleCode::Operations,
            self::HeadcountTurnover, self::Absenteeism, self::OvertimeTrend, self::BillableUtilization => RoleCode::Hr,
        };
    }

    public function widgetType(): WidgetType
    {
        return match ($this) {
            self::RevenueYtdVsBudget, self::OvertimeTrend, self::GrossMargin, self::ScrapRate, self::Absenteeism => WidgetType::AreaTrend,
            self::Ebitda, self::NetWorkingCapital, self::Oee => WidgetType::WaterfallChart,
            self::NetFinancialPosition, self::OrderBacklog, self::HeadcountFte, self::CashRunway, self::VatLiability, self::InventoryTurnover, self::HeadcountTurnover => WidgetType::MetricCard,
            self::Dso, self::Dpo, self::WinRate, self::Otif, self::BillableUtilization => WidgetType::Gauge,
            self::ArApAging => WidgetType::Heatmap,
            self::WeightedPipeline => WidgetType::Funnel,
            self::TopCustomers, self::TopProducts => WidgetType::TopNTable,
        };
    }

    /**
     * Whether the metric is a position at a date (closing spot rate) rather than a flow over the period (average rate).
     */
    public function isStock(): bool
    {
        return in_array($this, [self::NetFinancialPosition, self::NetWorkingCapital, self::ArApAging, self::VatLiability, self::OrderBacklog, self::CashRunway], true);
    }

    /**
     * Whether the KPI is an amount of money (converted to the display currency) rather than a ratio or a count.
     */
    public function isMonetary(): bool
    {
        return in_array($this, [
            self::RevenueYtdVsBudget, self::Ebitda, self::NetFinancialPosition, self::OrderBacklog, self::NetWorkingCapital,
            self::ArApAging, self::VatLiability, self::WeightedPipeline, self::TopCustomers, self::TopProducts,
        ], true);
    }

    /**
     * The documents behind the KPI, as the drill-down resolves them.
     */
    public function sourceDocumentType(): string
    {
        return match ($this) {
            self::RevenueYtdVsBudget, self::Ebitda, self::GrossMargin, self::NetFinancialPosition, self::NetWorkingCapital, self::CashRunway, self::InventoryTurnover => 'journal_entries',
            self::Dso, self::ArApAging, self::TopCustomers, self::TopProducts, self::VatLiability => 'invoices',
            self::Dpo => 'passive_invoices',
            self::OrderBacklog, self::Otif => 'sales_orders',
            self::WeightedPipeline, self::WinRate => 'opportunities',
            self::HeadcountFte, self::HeadcountTurnover => 'employees',
            self::Absenteeism => 'leave_requests',
            self::OvertimeTrend, self::BillableUtilization => 'timesheets',
            self::Oee, self::ScrapRate => 'production_orders',
        };
    }

    /**
     * Whether the figures expose compensation (hourly cost, salaries): shown only with the `kpi.hr.compensation` grant.
     */
    public function isCompensationSensitive(): bool
    {
        return $this === self::OvertimeTrend;
    }
}
