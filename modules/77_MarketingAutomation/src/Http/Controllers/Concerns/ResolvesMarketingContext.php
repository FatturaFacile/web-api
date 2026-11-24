<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Request;

trait ResolvesMarketingContext
{
    private function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
