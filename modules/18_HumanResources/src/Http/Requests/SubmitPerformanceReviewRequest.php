<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SubmitPerformanceReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'uuid'],
            'review_period' => ['required', 'string', 'max:30'],
            'goals_score' => ['required', 'numeric', 'min:1', 'max:5'],
            'competencies_score' => ['required', 'numeric', 'min:1', 'max:5'],
            'reviewer_employee_id' => ['nullable', 'uuid'],
            'review_type' => ['nullable', 'string', 'in:annual,mid_year,probation,promotion_readiness'],
            'promotion_recommended' => ['nullable', 'boolean'],
            'recommended_job_grade_id' => ['nullable', 'uuid'],
            'recommended_salary_increase_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'successor_readiness' => ['nullable', 'string', 'in:ready_now,ready_1_2_years,not_ready'],
            'goals' => ['nullable', 'array'],
            'competencies' => ['nullable', 'array'],
            'strengths' => ['nullable', 'string'],
            'improvement_areas' => ['nullable', 'string'],
        ];
    }
}
