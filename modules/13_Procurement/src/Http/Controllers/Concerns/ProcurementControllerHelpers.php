<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Active tenant, acting user and page size of the procurement endpoints.
 */
trait ProcurementControllerHelpers
{
    protected function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    protected function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', '25')));
    }
}
