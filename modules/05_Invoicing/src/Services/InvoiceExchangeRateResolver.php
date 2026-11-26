<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * Freezes the exchange rate of a sales document at emission. The rate of a document is units of the accounting currency for one
 * unit of the document currency (what the ledger multiplies by). A rate stated on the document is kept; a document in the
 * accounting currency takes 1; any other takes the rate of the multi-currency engine (Modules\Treasury\Currency): the rate of the
 * document date, or the latest earlier one within five days, inverted or crossed through EUR when needed. Without one the
 * document is refused with 422 `exchange_rate_missing` (a rate is never invented). Once frozen the rate never changes: payments
 * in a different rate produce exchange differences in the ledger, not a new invoice rate.
 */
final class InvoiceExchangeRateResolver
{
    public const string SOURCE_DOCUMENT = 'document';

    public const string SOURCE_ACCOUNTING_CURRENCY = 'accounting_currency';

    public const string SOURCE_SERVICE = 'exchange_rate_service';

    public function __construct(private readonly ?RateSelector $selector = null) {}

    /**
     * @throws ExchangeRateMissingException when the document is in a foreign currency, states no rate and the engine has none
     */
    public function freeze(SalesInvoice $invoice): void
    {
        if ($invoice->exchange_rate_source !== null) {
            return;
        }

        $tenantId = (string) $invoice->tenant_id;
        $accounting = TenantAccountingCurrency::of($tenantId);
        $currency = strtoupper((string) ($invoice->currency ?: $accounting));
        $date = CarbonImmutable::parse($invoice->date)->toDateString();

        if ($currency === $accounting) {
            $invoice->setAttribute('exchange_rate', '1.000000');
            $invoice->exchange_rate_source = self::SOURCE_ACCOUNTING_CURRENCY;
            $invoice->exchange_rate_date = $date;

            return;
        }

        $resolved = ($this->selector ?? app(RateSelector::class))->select(CurrencyPair::of($currency, $accounting), $date, $tenantId);

        $invoice->setAttribute('exchange_rate', $resolved->rate->value);
        $invoice->exchange_rate_source = self::SOURCE_SERVICE;
        $invoice->exchange_rate_date = $resolved->validOn;
    }
}
