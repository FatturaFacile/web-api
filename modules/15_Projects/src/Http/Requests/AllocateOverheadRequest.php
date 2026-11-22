<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Services\OverheadAllocationService;

/**
 * Allocation of an overhead pool of a cost centre (Mod 018) to projects.
 */
final class AllocateOverheadRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'run_key' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'cost_center_id' => ['required', 'uuid', $this->tenantOwns('cost_centers')],
            'pool_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'driver' => ['required', 'string', Rule::in(OverheadAllocationService::Drivers)],
            'weights' => ['required_if:driver,custom_weights', 'nullable', 'array', 'max:500'],
            'weights.*' => ['numeric', 'gte:0', 'max:999999999999'],
            'project_ids' => ['nullable', 'array', 'max:500'],
            'project_ids.*' => ['uuid', $this->tenantOwns('projects')],
        ];
    }
}
