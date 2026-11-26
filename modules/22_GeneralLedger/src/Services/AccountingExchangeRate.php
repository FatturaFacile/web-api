<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * The rate at which the ledger converts an amount of a foreign currency into the accounting currency of a tenant on a day, read
 * from the multi-currency engine (Modules\Treasury\Currency, Mod 003): the rate of the day, or the latest earlier one within five
 * days (weekends and holidays), directly, inverted or as a cross rate through EUR. It is accounting units per unit of the
 * currency, at the 6 decimals of `journal_lines.exchange_rate`, and the convention InvoiceExchangeRateResolver uses to freeze the
 * rate of a document. Adapter kept for the ledger services that ask for a rate and cope with its absence.
 */
final class AccountingExchangeRate
{
    /**
     * Accounting-currency units for one unit of the currency on the day; '1.000000' for the accounting currency itself,
     * null when the rate service has no rate on the day or within the five days before it.
     */
    public function rateOn(string $tenantId, string $currency, CarbonImmutable|string $date): ?string
    {
        $accounting = TenantAccountingCurrency::of($tenantId);
        $currency = strtoupper($currency);

        if ($currency === $accounting) {
            return '1.000000';
        }

        try {
            return app(RateSelector::class)->select(CurrencyPair::of($currency, $accounting), CarbonImmutable::parse($date)->toDateString(), $tenantId)->rate->value;
        } catch (ExchangeRateMissingException) {
            return null;
        }
    }
}
