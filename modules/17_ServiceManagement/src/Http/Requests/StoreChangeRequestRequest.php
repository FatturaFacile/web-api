<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'change_type' => ['nullable', 'string', 'in:standard,normal,emergency'],
            'risk_level' => ['nullable', 'string', 'in:low,medium,high,extreme'],
            'priority' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'service_category_id' => ['nullable', 'uuid'],
            'rollout_plan' => ['nullable', 'string'],
            'rollback_plan' => ['nullable', 'string'],
            'test_plan' => ['nullable', 'string'],
        ];
    }
}
