<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

/**
 * Existence and uniqueness rules limited to the active company (soft-deleted rows do not count).
 */
trait ScopesToTenant
{
    private function tenantExists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }

    /**
     * Rows of the company or global rows (tenant_id null) such as the shared units of measure and VAT codes.
     */
    private function sharedExists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where(static fn ($query) => $query->where(static fn ($inner) => $inner
            ->where('tenant_id', app(TenantContext::class)->getTenantId())->orWhereNull('tenant_id')));
    }

    private function tenantUnique(string $table, string $column): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }
}
