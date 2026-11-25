<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers\Concerns;

use App\Services\Tenancy\TenantContext;
use Modules\SalesBoundedContext\Exceptions\SalesBoundedContextException;

trait InteractsWithSalesBoundedContext
{
    protected function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId();
        if ($tenantId === null || $tenantId === '') {
            throw SalesBoundedContextException::invalidStateTransition('Active tenant context is required.');
        }

        return (string) $tenantId;
    }

    protected function perPage(): int
    {
        $raw = (int) request()->query('per_page', 25);

        return max(1, min(100, $raw));
    }
}
