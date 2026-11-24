<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;
use Modules\CreditManagement\Models\CreditScorecardFactor;

/**
 * Payload of a scorecard factor.
 */
final class ScorecardFactorRequest extends CreditFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $id = $this->route('id');
        $tenantId = (string) app(TenantContext::class)->getTenantId();

        return $this->forMethod([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('credit_scorecard_factors', 'code')->where('tenant_id', $tenantId)->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', Rule::in(CreditScorecardFactor::Sources)],
            'weight' => ['required', 'numeric', 'gt:0', 'max:100'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer', 'min:0', 'max:65000'],
        ]);
    }
}
