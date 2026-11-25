<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Requests\Concerns;

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
     * A row of the company or a global one (null tenant): formulas and audit templates can be presets.
     */
    private function tenantOrGlobalExists(string $table): Exists
    {
        return Rule::exists($table, 'id')->where(fn ($query) => $query->where(fn ($inner) => $inner->whereNull('tenant_id')->orWhere('tenant_id', app(TenantContext::class)->getTenantId()))->whereNull('deleted_at'));
    }

    private function tenantUnique(string $table, string $column = 'code'): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }
}
