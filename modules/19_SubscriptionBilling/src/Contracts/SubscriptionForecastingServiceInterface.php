<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

interface SubscriptionForecastingServiceInterface
{
    public function calculateMRR(string $tenantId, ?string $companyId = null): float;

    public function calculateARR(string $tenantId, ?string $companyId = null): float;

    public function generateCashForecast(string $tenantId, int $months = 12, ?string $companyId = null): array;
}
