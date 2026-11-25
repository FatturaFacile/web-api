<?php

declare(strict_types=1);

namespace Modules\Intercompany\Support;

use App\Models\Tenant;

/**
 * Names of the entities of a group for the resources, read once per request.
 */
final class TenantNames
{
    /**
     * @var array<string, string>
     */
    private static array $cache = [];

    public static function of(?string $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }

        return self::$cache[$tenantId] ??= (string) (Tenant::withoutGlobalScopes()->whereKey($tenantId)->value('name') ?? $tenantId);
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
