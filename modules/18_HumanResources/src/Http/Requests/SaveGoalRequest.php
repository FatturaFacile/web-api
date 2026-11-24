<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Models\Goal;

final class SaveGoalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The cycle, the level and the key results are fixed when the goal is created; later they change through their own
     * endpoints.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'goal_cycle_id' => $creating ? ['required', 'uuid', TenantScopedRules::exists('hr_goal_cycles')] : ['prohibited'],
            'level' => $creating ? ['required', Rule::in(Goal::Levels)] : ['prohibited'],
            'employee_id' => $creating ? ['required_if:level,'.Goal::LevelEmployee, 'nullable', 'uuid', TenantScopedRules::exists('hr_employees')] : ['prohibited'],
            'team_id' => $creating ? ['required_if:level,'.Goal::LevelTeam, 'nullable', 'uuid', TenantScopedRules::exists('hr_teams')] : ['prohibited'],
            'parent_goal_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_goals')],
            'title' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'weight' => ['sometimes', 'numeric', 'gt:0', 'max:1000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::in(Goal::Statuses)],
            'key_results' => $creating ? ['sometimes', 'array', 'max:20'] : ['prohibited'],
            'key_results.*.metric' => ['required', 'string', 'max:180'],
            'key_results.*.unit' => ['nullable', 'string', 'max:30'],
            'key_results.*.start_value' => ['nullable', 'numeric', 'between:-99999999999999,99999999999999'],
            'key_results.*.target_value' => ['required', 'numeric', 'between:-99999999999999,99999999999999'],
            'key_results.*.current_value' => ['nullable', 'numeric', 'between:-99999999999999,99999999999999'],
        ];
    }
}
