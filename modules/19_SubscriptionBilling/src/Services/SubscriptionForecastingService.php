<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Events\ARRForecastEvent;
use Modules\SubscriptionBilling\Events\MRRForecastEvent;
use Modules\SubscriptionBilling\Events\RecurringRevenueForecastEvent;
use Modules\SubscriptionBilling\Events\SubscriptionCashForecastEvent;
use Modules\SubscriptionBilling\Models\Subscription;

final readonly class SubscriptionForecastingService implements SubscriptionForecastingServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher
    ) {}

    public function calculateMRR(string $tenantId, ?string $companyId = null): float
    {
        $query = Subscription::where('tenant_id', $tenantId)->where('status', 'active');
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return round((float) $query->sum('mrr'), 2);
    }

    public function calculateARR(string $tenantId, ?string $companyId = null): float
    {
        $query = Subscription::where('tenant_id', $tenantId)->where('status', 'active');
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return round((float) $query->sum('arr'), 2);
    }

    public function generateCashForecast(string $tenantId, int $months = 12, ?string $companyId = null): array
    {
        $mrr = $this->calculateMRR($tenantId, $companyId);
        $arr = $this->calculateARR($tenantId, $companyId);

        $breakdown = [];
        $totalProjectedInflow = 0.00;

        $baseDate = now()->startOfMonth();

        for ($i = 0; $i < $months; $i++) {
            $month = $baseDate->copy()->addMonths($i)->format('Y-m');
            // Factoring in estimated 1.5% monthly net expansion
            $projectedMonth = round($mrr * pow(1.015, $i), 2);
            $breakdown[$month] = $projectedMonth;
            $totalProjectedInflow += $projectedMonth;
        }

        $totalProjectedInflow = round($totalProjectedInflow, 2);

        $this->dispatcher->dispatch(new SubscriptionCashForecastEvent(
            forecastPeriod: now()->format('Y-m').' to '.now()->addMonths($months - 1)->format('Y-m'),
            projectedCashInflow: $totalProjectedInflow,
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $companyId ?? $tenantId,
            breakdown: $breakdown
        ));

        $this->dispatcher->dispatch(new MRRForecastEvent(
            targetMonth: now()->addMonth()->format('Y-m'),
            startingMrr: $mrr,
            newMrr: round($mrr * 0.08, 2),
            expansionMrr: round($mrr * 0.04, 2),
            contractionMrr: round($mrr * 0.01, 2),
            churnMrr: round($mrr * 0.02, 2),
            endingMrr: round($mrr * 1.09, 2),
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $companyId ?? $tenantId
        ));

        $this->dispatcher->dispatch(new ARRForecastEvent(
            targetYear: now()->format('Y'),
            startingArr: $arr,
            projectedArr: round($arr * 1.15, 2),
            netRetentionRate: 1.12,
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $companyId ?? $tenantId
        ));

        $this->dispatcher->dispatch(new RecurringRevenueForecastEvent(
            fiscalQuarter: 'Q'.ceil((int) now()->format('n') / 3).'-'.now()->format('Y'),
            contractedRecurringRevenue: round($mrr * 3, 2),
            estimatedUsageRevenue: round($mrr * 0.15 * 3, 2),
            netExpansionRevenue: round($mrr * 0.08 * 3, 2),
            currency: 'EUR',
            tenantId: $tenantId,
            companyId: $companyId ?? $tenantId
        ));

        return [
            'current_mrr' => $mrr,
            'current_arr' => $arr,
            'forecast_months' => $months,
            'projected_cash_inflow' => $totalProjectedInflow,
            'monthly_breakdown' => $breakdown,
        ];
    }
}
