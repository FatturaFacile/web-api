<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;

/**
 * The query-string filters of every list of the module.
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
            'status' => ['nullable', 'string', Rule::in([...ReportStatus::values(), ...ReconciliationStatus::values()])],
            'employee_id' => ['nullable', 'uuid'],
            'period_from' => ['nullable', 'date'],
            'period_to' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'source' => ['nullable', 'string', 'max:20'],
            'vehicle_code' => ['nullable', 'string', 'max:40'],
            'fuel' => ['nullable', 'string', 'max:20'],
            'vehicle_class' => ['nullable', 'string', 'max:40'],
            'on_date' => ['nullable', 'date'],
            'severity' => ['nullable', Rule::in(['warning', 'blocking'])],
            'report_id' => ['nullable', 'uuid'],
            'card_account_id' => ['nullable', 'uuid'],
        ];
    }
}
