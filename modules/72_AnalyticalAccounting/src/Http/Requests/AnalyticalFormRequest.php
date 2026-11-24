<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Base of the analytical accounting requests: authorization is decided by the module access rules and the policy.
 */
abstract class AnalyticalFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function tenantId(): string
    {
        $tenantId = app(TenantContext::class)->getTenantId() ?? $this->user()?->tenant_id;
        abort_if($tenantId === null, 403, 'Missing or unresolvable tenant context');

        return (string) $tenantId;
    }

    /**
     * An existing, not deleted row of the table inside the active company.
     */
    protected function owned(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('tenant_id', $this->tenantId())->whereNull('deleted_at');
    }
}
