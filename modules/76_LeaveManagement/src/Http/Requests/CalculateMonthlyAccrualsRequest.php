<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Modules\LeaveManagement\Domain\Enums\LeaveCountry;
use Modules\LeaveManagement\Models\EmployeeLeaveAccrualLedger;

final class CalculateMonthlyAccrualsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manageConfiguration', EmployeeLeaveAccrualLedger::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'employee_id' => ['nullable', 'uuid'],
            'country' => ['nullable', Rule::enum(LeaveCountry::class)],
        ];
    }
}
