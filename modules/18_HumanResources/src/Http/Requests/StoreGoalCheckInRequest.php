<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreGoalCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A check-in carries the new value of a key result, or the progress of a goal that has no key results.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key_result_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_goal_key_results')],
            'value' => ['required_with:key_result_id', 'nullable', 'numeric', 'between:-99999999999999,99999999999999'],
            'progress' => ['required_without:key_result_id', 'nullable', 'numeric', 'between:0,100'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
