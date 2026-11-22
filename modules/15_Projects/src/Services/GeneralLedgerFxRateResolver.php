<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\AccountingExchangeRate;
use Modules\Projects\Contracts\ProjectFxRateResolverInterface;

/**
 * Default exchange-rate adapter: the rate the General Ledger uses to convert into the accounting currency of the tenant.
 */
final readonly class GeneralLedgerFxRateResolver implements ProjectFxRateResolverInterface
{
    public function __construct(private AccountingExchangeRate $rates) {}

    public function rateOn(string $tenantId, string $currency, string $date): ?string
    {
        return $this->rates->rateOn($tenantId, $currency, CarbonImmutable::parse($date));
    }
}
