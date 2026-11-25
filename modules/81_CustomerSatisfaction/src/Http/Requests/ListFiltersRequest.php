<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\CustomerSatisfaction\Domain\Enums\DispatchStatus;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryPriority;
use Modules\CustomerSatisfaction\Domain\Enums\RecoveryStatus;
use Modules\CustomerSatisfaction\Domain\Enums\ReportGroupBy;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyTriggerEvent;
use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;

/**
 * Query string of the lists and of the analytics: paging, search, status filters and the period and dimension filters.
 */
final class ListFiltersRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'survey_type' => ['nullable', Rule::enum(SurveyType::class)],
            'trigger_event' => ['nullable', Rule::enum(SurveyTriggerEvent::class)],
            'dispatch_status' => ['nullable', Rule::enum(DispatchStatus::class)],
            'status' => ['nullable', Rule::enum(RecoveryStatus::class)],
            'priority' => ['nullable', Rule::enum(RecoveryPriority::class)],
            'is_detractor' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'customer_id' => ['nullable', 'uuid'],
            'department_code' => ['nullable', 'string', 'max:60'],
            'technician_id' => ['nullable', 'uuid'],
            'product_line_code' => ['nullable', 'string', 'max:60'],
            'branch_code' => ['nullable', 'string', 'max:60'],
            'group_by' => ['nullable', Rule::enum(ReportGroupBy::class)],
            'granularity' => ['nullable', Rule::in(['day', 'week', 'month', 'quarter'])],
            'open_only' => ['nullable', 'boolean'],
        ];
    }
}
