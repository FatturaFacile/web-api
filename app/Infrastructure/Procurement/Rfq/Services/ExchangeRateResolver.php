<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\Contracts\ExchangeRateResolverInterface;
use App\Models\ExchangeRate;
use Modules\Procurement\Support\Decimal;

/**
 * Resolves latest exchange rate between two ISO-4217 currency codes for multi-currency RFQ comparison.
 */
final class ExchangeRateResolver implements ExchangeRateResolverInterface
{
    public function resolveRate(string $tenantId, string $fromCurrency, string $toCurrency): string
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === '' || $to === '' || $from === $to) {
            return '1.000000';
        }

        $direct = ExchangeRate::query()
            ->where(static fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('source', $from)
            ->where('target', $to)
            ->orderByDesc('effective_date')
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->value('rate');

        if ($direct !== null && Decimal::cmp((string) $direct, '0', 6) > 0) {
            return Decimal::of((string) $direct, 6);
        }

        $inverse = ExchangeRate::query()
            ->where(static fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('source', $to)
            ->where('target', $from)
            ->orderByDesc('effective_date')
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->value('rate');

        if ($inverse !== null && Decimal::cmp((string) $inverse, '0', 6) > 0) {
            return Decimal::div('1', (string) $inverse, 6);
        }

        return '1.000000';
    }
}
