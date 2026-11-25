<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests\Concerns;

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

    private function tenantUnique(string $table, string $column = 'code'): Unique
    {
        return Rule::unique($table, $column)->where('tenant_id', app(TenantContext::class)->getTenantId())->whereNull('deleted_at');
    }
}
