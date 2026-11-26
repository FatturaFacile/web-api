<?php

declare(strict_types=1);

namespace Modules\Workflow\Support;

use App\Services\Tenancy\TenantContext;

/**
 * Approval thresholds are set in the functional currency of the company. A document in a foreign currency is converted
 * with the rate stored on the document (Mod 003 multi-currency: units of functional currency per unit of document
 * currency), never with today's rate, so the same document always falls in the same tier.
 */
final class FunctionalAmount
{
    /**
     * The amount in the functional currency.
     *
     * @param  ?string  $functionalCurrency  null reads the default currency of the active tenant
     */
    public static function of(float $amount, ?string $currency, int|float|string|null $rate, ?string $functionalCurrency = null): float
    {
        $functional = $functionalCurrency ?? (string) (app(TenantContext::class)->getTenant()->default_currency ?? '');

        if ($currency === null || $currency === '' || $functional === '' || strtoupper($currency) === strtoupper($functional)) {
            return round($amount, 2);
        }

        $factor = is_numeric($rate) ? (float) $rate : 0.0;

        return $factor > 0.0 ? round($amount * $factor, 2) : round($amount, 2);
    }
}
