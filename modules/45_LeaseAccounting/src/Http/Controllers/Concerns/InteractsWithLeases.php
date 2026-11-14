<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Modules\LeaseAccounting\Models\LeaseContract;

/**
 * Tenant, user and record lookup of the request for the lease controllers.
 */
trait InteractsWithLeases
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

    private function findContract(string $id): LeaseContract
    {
        return LeaseContract::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
