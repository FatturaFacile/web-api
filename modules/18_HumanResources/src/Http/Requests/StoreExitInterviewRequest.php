<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\ExitReasonCode;

final class StoreExitInterviewRequest extends FormRequest
{
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
            'employee_id' => ['required', 'uuid', TenantScopedRules::exists('hr_employees')],
            'interviewer_employee_id' => ['nullable', 'uuid', TenantScopedRules::exists('hr_employees')],
            'conducted_on' => ['required', 'date_format:Y-m-d'],
            'reason_code' => ['required', Rule::in(ExitReasonCode::values())],
            'would_recommend' => ['nullable', 'boolean'],
            'eligible_for_rehire' => ['nullable', 'boolean'],
            'satisfaction_rating' => ['nullable', 'integer', 'between:1,5'],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
