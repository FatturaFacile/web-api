<?php

declare(strict_types=1);

namespace Modules\Marketplace\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Tenancy\TenantContext;

/**
 * Tenant, user and pagination of the request for the marketplace controllers.
 */
trait InteractsWithMarketplace
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }
}
