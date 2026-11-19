<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the controlling requests: authorization is decided by the module access rules and the policy.
 */
abstract class ControllingFormRequest extends FormRequest
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
}
