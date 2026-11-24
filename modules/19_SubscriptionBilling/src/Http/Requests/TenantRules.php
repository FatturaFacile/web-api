<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Existence and uniqueness rules restricted to the records of the active company.
 */
trait TenantRules
{
    protected function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    protected function existsInTenant(string $table, string $column = 'id'): Exists
    {
        $tenantId = $this->tenantId();

        return (new Exists($table, $column))->where(fn ($query) => $query->where('tenant_id', $tenantId));
    }

    protected function uniqueInTenant(string $table, string $column): Unique
    {
        $tenantId = $this->tenantId();

        return (new Unique($table, $column))->where(fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
