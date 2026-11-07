<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validation of references to tenant tables: the row must belong to the active tenant or be generic.
 */
trait TenantScopedRules
{
    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, $column)->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }

    /**
     * The user must be a member of the active tenant.
     */
    protected function tenantMember(): Exists
    {
        return Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId());
    }

    /**
     * Like tenantExists, for a row the tenant must own: generic rows are refused.
     */
    protected function tenantOwns(string $table, string $column = 'id'): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, $column)->where(fn ($query) => $query->where('tenant_id', $tenantId));
    }
}
