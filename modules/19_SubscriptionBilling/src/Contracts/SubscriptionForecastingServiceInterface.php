<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

/**
 * Contracted recurring revenue: MRR, ARR and the cash forecast of the coming invoices.
 */
interface SubscriptionForecastingServiceInterface
{
    /**
     * MRR of the counted subscriptions, as a decimal string.
     */
    public function calculateMRR(string $tenantId, ?string $companyId = null): string;

    /**
     * ARR of the counted subscriptions, as a decimal string.
     */
    public function calculateARR(string $tenantId, ?string $companyId = null): string;

    /**
     * Net amounts to invoice per month over the next months.
     *
     * @return array{current_mrr: string, current_arr: string, forecast_months: int, projected_cash_inflow: string, monthly_breakdown: array<string, string>}
     */
    public function generateCashForecast(string $tenantId, int $months = 12, ?string $companyId = null): array;
}
