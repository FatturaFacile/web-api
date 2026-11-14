<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Requests\Concerns;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Rule helpers shared by the facility form requests.
 */
trait FacilityRules
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access middleware and the policy of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The value must be unique among the live records of the active company; the record being edited is ignored.
     */
    protected function uniqueInTenant(string $table, string $column): Unique
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return Rule::unique($table, $column)
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'))
            ->ignore($this->route('id'));
    }
}
