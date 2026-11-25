<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests\Concerns;

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

    private function tenantMember(): Exists
    {
        return Rule::exists('tenant_user', 'user_id')->where('tenant_id', app(TenantContext::class)->getTenantId());
    }

    private function tenantUnique(string $table, string $column = 'code', ?string $ignoreId = null): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at')->ignore($ignoreId);
    }
}
