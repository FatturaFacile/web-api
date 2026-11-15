<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Models\GoalCycle;

final class SaveGoalCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Closing goes through its own action, so a cycle is saved as draft or active only.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:120'],
            'starts_on' => [$required, 'date_format:Y-m-d'],
            'ends_on' => [$required, 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['sometimes', Rule::in([GoalCycle::StatusDraft, GoalCycle::StatusActive])],
        ];
    }
}
