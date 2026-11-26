<?php

declare(strict_types=1);

namespace App\Services\Reports\DailySummary;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Support\Carbon;

/**
 * Deterministic rule-based and statistical insight generator for the ERP daily summary.
 */
final class ErpInsightsGenerator
{
    public function __construct(private readonly ReportLocaleHelper $localeHelper) {}

    /**
     * @param  array<string, mixed>  $sections
     * @return list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>
     */
    public function generate(Tenant $tenant, Carbon $localNow, string $locale, array $sections): array
    {
        $insights = [];
        $currency = $tenant->default_currency ?: 'EUR';

        $this->appendRevenueTrendInsight($insights, $tenant, $localNow, $locale, $currency);
        $this->appendOverdue30DaysInsight($insights, $tenant, $localNow, $locale, $currency);
        $this->appendCollectionsQuarterAverageInsight($insights, $tenant, $localNow, $locale, $currency);
        $this->appendDsoDeteriorationInsight($insights, $tenant, $localNow, $locale);
        $this->appendCustomerGrowthInsight($insights, $tenant, $localNow, $locale);
        $this->appendOperationalRiskInsights($insights, $tenant, $localNow, $locale, $sections);

        return $insights;
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     */
    private function appendRevenueTrendInsight(array &$insights, Tenant $tenant, Carbon $localNow, string $locale, string $currency): void
    {
        $currentStart = $localNow->copy()->startOfMonth()->toDateString();
        $currentEnd = $localNow->toDateString();

        $prevMonthStart = $localNow->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $prevMonthEnd = $localNow->copy()->subMonthNoOverflow()->endOfMonth()->toDateString();

        $currentRevenue = (float) Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('invoice_type', '!=', 'proforma')
            ->where('invoice_type', '!=', 'credit_note')
            ->whereBetween('date', [$currentStart, $currentEnd])
            ->sum('total_gross');

        $previousRevenue = (float) Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->where('invoice_type', '!=', 'proforma')
            ->where('invoice_type', '!=', 'credit_note')
            ->whereBetween('date', [$prevMonthStart, $prevMonthEnd])
            ->sum('total_gross');

        if ($previousRevenue <= 0.0) {
            return;
        }

        $deltaPct = round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1);

        if ($deltaPct >= 5.0) {
            $insights[] = [
                'code' => 'revenue_growth',
                'severity' => 'success',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.REVENUE_GROWTH_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.REVENUE_GROWTH_DESC', $locale, [
                    'percent' => number_format($deltaPct, 1),
                    'current' => $this->localeHelper->formatCurrency($currentRevenue, $currency, $locale),
                ], $tenant->id),
                'metric_value' => $deltaPct,
            ];
        } elseif ($deltaPct <= -10.0) {
            $insights[] = [
                'code' => 'revenue_drop',
                'severity' => 'warning',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.REVENUE_DROP_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.REVENUE_DROP_DESC', $locale, [
                    'percent' => number_format(abs($deltaPct), 1),
                    'current' => $this->localeHelper->formatCurrency($currentRevenue, $currency, $locale),
                ], $tenant->id),
                'metric_value' => $deltaPct,
            ];
        }
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     */
    private function appendOverdue30DaysInsight(array &$insights, Tenant $tenant, Carbon $localNow, string $locale, string $currency): void
    {
        $cutoff = $localNow->copy()->subDays(30)->toDateString();

        $row = Invoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', $cutoff)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_gross - total_paid), 0) as total_due')
            ->toBase()
            ->first();

        $count = (int) ($row->cnt ?? 0);
        $totalDue = round((float) ($row->total_due ?? 0), 2);

        if ($count > 0) {
            $insights[] = [
                'code' => 'overdue_30d_alert',
                'severity' => 'critical',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.OVERDUE_30D_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.OVERDUE_30D_DESC', $locale, [
                    'count' => $count,
                    'amount' => $this->localeHelper->formatCurrency($totalDue, $currency, $locale),
                ], $tenant->id),
                'metric_value' => $count,
            ];
        }
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     */
    private function appendCollectionsQuarterAverageInsight(array &$insights, Tenant $tenant, Carbon $localNow, string $locale, string $currency): void
    {
        $last30Start = $localNow->copy()->subDays(30)->toDateString();
        $last90Start = $localNow->copy()->subDays(90)->toDateString();
        $today = $localNow->toDateString();

        $last30 = (float) Payment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('paid_at', [$last30Start, $today])
            ->sum('amount');

        $last90 = (float) Payment::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('paid_at', [$last90Start, $today])
            ->sum('amount');

        $quarterlyMonthlyAvg = $last90 / 3.0;

        if ($quarterlyMonthlyAvg > 0.0 && $last30 < ($quarterlyMonthlyAvg * 0.85)) {
            $dropPct = round((($quarterlyMonthlyAvg - $last30) / $quarterlyMonthlyAvg) * 100, 1);
            $insights[] = [
                'code' => 'collections_below_avg',
                'severity' => 'warning',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.COLLECTIONS_BELOW_AVG_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.COLLECTIONS_BELOW_AVG_DESC', $locale, [
                    'percent' => number_format($dropPct, 1),
                    'current' => $this->localeHelper->formatCurrency($last30, $currency, $locale),
                    'average' => $this->localeHelper->formatCurrency($quarterlyMonthlyAvg, $currency, $locale),
                ], $tenant->id),
                'metric_value' => $dropPct,
            ];
        }
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     */
    private function appendDsoDeteriorationInsight(array &$insights, Tenant $tenant, Carbon $localNow, string $locale): void
    {
        $recentPayments = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('payments.tenant_id', $tenant->id)
            ->whereBetween('payments.paid_at', [$localNow->copy()->subDays(30)->toDateString(), $localNow->toDateString()])
            ->select(['payments.paid_at', 'invoices.date as invoice_date'])
            ->toBase()
            ->get();

        $priorPayments = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('payments.tenant_id', $tenant->id)
            ->whereBetween('payments.paid_at', [$localNow->copy()->subDays(60)->toDateString(), $localNow->copy()->subDays(31)->toDateString()])
            ->select(['payments.paid_at', 'invoices.date as invoice_date'])
            ->toBase()
            ->get();

        if ($recentPayments->isEmpty() || $priorPayments->isEmpty()) {
            return;
        }

        $avgRecent = $recentPayments->avg(static fn (object $row): float => (float) max(0, Carbon::parse((string) $row->invoice_date)->diffInDays(Carbon::parse((string) $row->paid_at), false)));
        $avgPrior = $priorPayments->avg(static fn (object $row): float => (float) max(0, Carbon::parse((string) $row->invoice_date)->diffInDays(Carbon::parse((string) $row->paid_at), false)));

        if ($avgRecent !== null && $avgPrior !== null && ($avgRecent - $avgPrior) >= 5.0) {
            $insights[] = [
                'code' => 'dso_deterioration',
                'severity' => 'warning',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.DSO_DETERIORATION_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.DSO_DETERIORATION_DESC', $locale, [
                    'recent_days' => (int) round($avgRecent),
                    'prior_days' => (int) round($avgPrior),
                ], $tenant->id),
                'metric_value' => (int) round($avgRecent),
            ];
        }
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     */
    private function appendCustomerGrowthInsight(array &$insights, Tenant $tenant, Carbon $localNow, string $locale): void
    {
        $newCustomers = Contact::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('type', ['customer', 'client', 'both'])
            ->where('created_at', '>=', $localNow->copy()->subDays(30))
            ->count();

        if ($newCustomers > 0) {
            $insights[] = [
                'code' => 'customer_growth',
                'severity' => 'info',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.CUSTOMER_GROWTH_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.CUSTOMER_GROWTH_DESC', $locale, [
                    'count' => $newCustomers,
                ], $tenant->id),
                'metric_value' => $newCustomers,
            ];
        }
    }

    /**
     * @param  list<array{code: string, severity: string, title: string, description: string, metric_value: string|float|int|null}>  $insights
     * @param  array<string, mixed>  $sections
     */
    private function appendOperationalRiskInsights(array &$insights, Tenant $tenant, Carbon $localNow, string $locale, array $sections): void
    {
        $expiringSla = Ticket::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotIn('status', ['closed', 'resolved'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<=', $localNow->copy()->addHours(24))
            ->count();

        if ($expiringSla > 0) {
            $insights[] = [
                'code' => 'sla_breach_alert',
                'severity' => 'critical',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.SLA_BREACH_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.SLA_BREACH_DESC', $locale, [
                    'count' => $expiringSla,
                ], $tenant->id),
                'metric_value' => $expiringSla,
            ];
        }

        $belowMinStock = (int) (($sections['inventory']['below_min_stock_count'] ?? 0));
        if ($belowMinStock > 0) {
            $insights[] = [
                'code' => 'low_stock_alert',
                'severity' => 'warning',
                'title' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.LOW_STOCK_TITLE', $locale),
                'description' => $this->localeHelper->trans('SCHEDULED_REPORT.INSIGHT.LOW_STOCK_DESC', $locale, [
                    'count' => $belowMinStock,
                ], $tenant->id),
                'metric_value' => $belowMinStock,
            ];
        }
    }
}
