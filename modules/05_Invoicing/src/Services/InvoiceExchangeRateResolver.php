<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Models\SalesInvoice;
use RoundingMode;

/**
 * Freezes the exchange rate of a sales document at emission. The rate of a document is units of the accounting
 * currency for one unit of the document currency (what the ledger multiplies by). A rate stated on the document is kept;
 * a document in the accounting currency takes 1; any other takes the rate of the exchange-rate service (`exchange_rates`,
 * base = accounting currency, latest rate published on or before the document date), inverted. Once frozen the rate
 * never changes: payments in a different rate produce exchange differences in the ledger, not a new invoice rate.
 */
final class InvoiceExchangeRateResolver
{
    public const string SOURCE_DOCUMENT = 'document';

    public const string SOURCE_ACCOUNTING_CURRENCY = 'accounting_currency';

    public const string SOURCE_SERVICE = 'exchange_rate_service';

    /**
     * @throws ValidationException when the document is in a foreign currency, states no rate and the service has none
     */
    public function freeze(SalesInvoice $invoice): void
    {
        if ($invoice->exchange_rate_source !== null) {
            return;
        }

        $accounting = TenantAccountingCurrency::of((string) $invoice->tenant_id);
        $currency = strtoupper((string) ($invoice->currency ?: $accounting));
        $date = CarbonImmutable::parse($invoice->date)->toDateString();

        if ($currency === $accounting) {
            $invoice->setAttribute('exchange_rate', '1.000000');
            $invoice->exchange_rate_source = self::SOURCE_ACCOUNTING_CURRENCY;
            $invoice->exchange_rate_date = $date;

            return;
        }

        $published = ExchangeRate::query()
            ->where('source', $accounting)
            ->where('target', $currency)
            ->whereDate('effective_date', '<=', $date)
            ->latest('effective_date')
            ->first();

        if ($published === null || ! Decimal::isPositive((string) $published->rate)) {
            throw ValidationException::withMessages(['exchange_rate' => __('sales.exchange_rate_missing', ['base' => $accounting, 'currency' => $currency, 'date' => $date])]);
        }

        $invoice->setAttribute('exchange_rate', bcadd(bcround(Decimal::div('1', (string) $published->rate), 6, RoundingMode::HalfAwayFromZero), '0', 6));
        $invoice->exchange_rate_source = self::SOURCE_SERVICE;
        $invoice->exchange_rate_date = CarbonImmutable::parse((string) $published->getAttribute('effective_date'))->toDateString();
    }
}
