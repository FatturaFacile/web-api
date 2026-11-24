<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Rfq\Contracts;

/**
 * Resolves exchange rates from bid currency to RFQ base currency.
 */
interface ExchangeRateResolverInterface
{
    public function resolveRate(string $tenantId, string $fromCurrency, string $toCurrency): string;
}
