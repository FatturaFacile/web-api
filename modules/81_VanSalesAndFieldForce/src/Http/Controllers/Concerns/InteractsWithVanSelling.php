<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;

trait InteractsWithVanSelling
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): ?string
    {
        $user = request()->user();

        return $user !== null ? (string) $user->getKey() : null;
    }

    private function perPage(int $default = 25): int
    {
        return max(1, min(100, (int) request()->query('per_page', $default)));
    }
}
