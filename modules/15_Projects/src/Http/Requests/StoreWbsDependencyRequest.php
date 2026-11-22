<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Wbs\Enums\DependencyType;

/**
 * A precedence link between two leaf nodes of the WBS: FS, SS, FF or SF with a lag in calendar days (negative = lead).
 */
class StoreWbsDependencyRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('dependency_type'))) {
            $this->merge(['dependency_type' => strtoupper($this->input('dependency_type'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'predecessor_node_id' => ['required', 'uuid', $this->tenantOwns('project_wbs_nodes')],
            'successor_node_id' => ['required', 'uuid', 'different:predecessor_node_id', $this->tenantOwns('project_wbs_nodes')],
            'dependency_type' => ['nullable', 'string', Rule::enum(DependencyType::class)],
            'lag_days' => ['nullable', 'integer', 'between:-3650,3650'],
        ];
    }
}
