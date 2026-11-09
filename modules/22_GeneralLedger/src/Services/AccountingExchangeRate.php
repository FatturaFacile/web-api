<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use RoundingMode;

/**
 * The rate at which the ledger converts an amount of a foreign currency into the accounting currency of a tenant on a
 * day, read from the exchange-rate service (`exchange_rates`, source = accounting currency, target = foreign currency,
 * latest rate published on or before the day). The service publishes foreign units per accounting unit; the ledger
 * multiplies by accounting units per foreign unit, so the published rate is inverted, at the 6 decimals of
 * `journal_lines.exchange_rate`. It is the convention InvoiceExchangeRateResolver uses to freeze the rate of a document.
 */
final class AccountingExchangeRate
{
    /**
     * Accounting-currency units for one unit of the currency on the day; '1.000000' for the accounting currency itself,
     * null when the service has no rate on or before the day.
     */
    public function rateOn(string $tenantId, string $currency, CarbonImmutable|string $date): ?string
    {
        $accounting = TenantAccountingCurrency::of($tenantId);
        $currency = strtoupper($currency);

        if ($currency === $accounting) {
            return '1.000000';
        }

        $published = ExchangeRate::query()
            ->where('source', $accounting)
            ->where('target', $currency)
            ->whereDate('effective_date', '<=', CarbonImmutable::parse($date)->toDateString())
            ->latest('effective_date')
            ->value('rate');

        if ($published === null || ! Decimal::isPositive((string) $published)) {
            return null;
        }

        return bcadd(bcround(Decimal::div('1', (string) $published), 6, RoundingMode::HalfAwayFromZero), '0', 6);
    }
}
