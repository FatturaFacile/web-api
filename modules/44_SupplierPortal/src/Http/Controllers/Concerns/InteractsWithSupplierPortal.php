<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Modules\Procurement\Models\Supplier;

/**
 * Tenant, user and lookups of the request for the supplier portal controllers.
 */
trait InteractsWithSupplierPortal
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

    private function supplier(string $supplierId): Supplier
    {
        return Supplier::query()->where('tenant_id', $this->tenantId())->findOrFail($supplierId);
    }
}
