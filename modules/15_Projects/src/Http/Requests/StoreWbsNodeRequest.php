<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Wbs\Enums\WbsNodeStatus;
use Modules\Projects\Wbs\Enums\WbsNodeType;

/**
 * A node of the WBS: below `parent_node_id` (top level when empty) at the 1-based position `sort_order` (last when empty).
 * `node_type` accepts the snake_case value or the PascalCase contract name.
 */
class StoreWbsNodeRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $type = $this->input('node_type');

        if (is_string($type) && ($normalised = WbsNodeType::fromInput($type)) !== null) {
            $this->merge(['node_type' => $normalised->value]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->presenceRule();

        return [
            'title' => [$presence, 'string', 'max:255'],
            'parent_node_id' => ['nullable', 'uuid', $this->tenantOwns('project_wbs_nodes')],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'node_type' => ['nullable', 'string', Rule::enum(WbsNodeType::class)],
            'status' => ['nullable', 'string', Rule::enum(WbsNodeStatus::class)],
            'required_role' => ['nullable', 'string', 'max:100'],
            'planned_hours' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'actual_hours' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'planned_cost' => ['nullable', 'numeric', 'gte:0', 'max:99999999999999'],
            'actual_cost' => ['nullable', 'numeric', 'gte:0', 'max:99999999999999'],
            'planned_revenue' => ['nullable', 'numeric', 'gte:0', 'max:99999999999999'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'percent_complete' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    protected function presenceRule(): string
    {
        return 'required';
    }
}
