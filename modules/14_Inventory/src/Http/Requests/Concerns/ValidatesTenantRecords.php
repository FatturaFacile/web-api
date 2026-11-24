<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests\Concerns;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesTenantRecords
{
    /**
     * The record must exist for the active tenant, or be a generic record shared by every tenant.
     */
    protected function tenantExists(string $table, string $column = 'id'): Exists
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::exists($table, $column)->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
    }
}
