<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

/**
 * Port to the exchange-rate service: how many units of the tenant's base (accounting) currency one unit of a currency is
 * worth on a day. Implementations answer '1.000000' for the base currency and null when no rate is published.
 */
interface ProjectFxRateResolverInterface
{
    public function rateOn(string $tenantId, string $currency, string $date): ?string;
}
