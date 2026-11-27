<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Base of the green care requests: access is decided by the module access middleware and the policies; ids and unique values are checked
 * inside the active company.
 */
abstract class GreenCareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function tenantId(): string
    {
        return (string) app(TenantContext::class)->getTenantId();
    }

    protected function inTenant(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('tenant_id', $this->tenantId())->whereNull('deleted_at');
    }

    protected function uniqueInTenant(string $table, string $column, ?string $ignoreId = null): Unique
    {
        $rule = Rule::unique($table, $column)->where('tenant_id', $this->tenantId())->whereNull('deleted_at');

        return $ignoreId === null ? $rule : $rule->ignore($ignoreId);
    }
}
