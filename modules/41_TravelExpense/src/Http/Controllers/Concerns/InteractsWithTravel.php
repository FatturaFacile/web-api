<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Modules\TravelExpense\Models\TravelTrip;

/**
 * Tenant, user and trip lookup of the request for the travel controllers.
 */
trait InteractsWithTravel
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? request()->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    private function userId(): string
    {
        return (string) request()->user()->getKey();
    }

    private function perPage(): int
    {
        return max(1, min(100, (int) request()->query('per_page', 25)));
    }

    private function findTrip(string $id): TravelTrip
    {
        return TravelTrip::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
