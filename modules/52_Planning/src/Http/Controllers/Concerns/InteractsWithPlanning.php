<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;

/**
 * Tenant, user and pagination of the request for the planning controllers.
 */
trait InteractsWithPlanning
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): ?string
    {
        $id = request()->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }
}
