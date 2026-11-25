<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;

final class GenerateReportRequest extends FormRequest
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
            'title' => ['nullable', 'string', 'max:200'],
            'group_by' => ['sometimes', Rule::enum(ReportGroupBy::class)],
            'filters' => ['nullable', 'array'],
            'filters.from' => ['nullable', 'date'],
            'filters.to' => ['nullable', 'date', 'after_or_equal:filters.from'],
            'filters.survey_type' => ['nullable', Rule::enum(SurveyType::class)],
            'filters.department_code' => ['nullable', 'string', 'max:60'],
            'filters.technician_id' => ['nullable', 'uuid'],
            'filters.product_line_code' => ['nullable', 'string', 'max:60'],
            'filters.branch_code' => ['nullable', 'string', 'max:60'],
            'management_review_notes' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
