<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ResolvesTenant
{
    protected function resolveTenantId(Request $request): string
    {
        $tenantContext = app(TenantContext::class);
        $tenant = $tenantContext->getTenant();
        if ($tenant) {
            return (string) $tenant->id;
        }

        $user = $request->user();

        return (string) ($user?->current_tenant_id ?? $user?->tenant_id ?? '019318b7-0000-7000-8000-000000000001');
    }
}
