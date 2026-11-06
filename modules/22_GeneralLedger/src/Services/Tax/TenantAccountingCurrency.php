<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use App\Models\Tenant;

/**
 * The accounting currency of a tenant.
 */
final class TenantAccountingCurrency
{
    private const string FALLBACK = 'EUR';

    public static function of(string $tenantId): string
    {
        $currency = Tenant::query()->whereKey($tenantId)->value('default_currency');

        return is_string($currency) && $currency !== '' ? strtoupper($currency) : self::FALLBACK;
    }
}
