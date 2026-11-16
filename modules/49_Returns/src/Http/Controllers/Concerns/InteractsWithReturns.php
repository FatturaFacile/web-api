<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;

/**
 * Tenant and user of the request for the returns controllers.
 */
trait InteractsWithReturns
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

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }
}
