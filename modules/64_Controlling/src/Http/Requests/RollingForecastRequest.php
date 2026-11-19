<?php

declare(strict_types=1);

namespace Modules\Controlling\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Validation of the RollingForecastRequest payload; every reference is limited to the active company.
 */
final class RollingForecastRequest extends ControllingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenant = $this->tenantId();

        return [
            'cutoff_month' => ['required', 'integer', 'between:1,12'], 'method' => ['nullable', Rule::in(['run_rate', 'budget'])],
            'code' => ['nullable', 'string', 'max:40', Rule::unique('controlling_budget_plans', 'code')->where('tenant_id', $tenant)->whereNull('deleted_at')],
            'overrides' => ['nullable', 'array'], 'overrides.*' => ['array'], 'overrides.*.*' => ['numeric'],
        ];
    }
}
