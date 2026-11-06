<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Services\TimeTracking\SwissHolidayCalendar;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;
use Modules\HumanResources\Services\TimeTracking\TimeRecordingMode;

final class SaveTimeProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Art. 73a and 73b OLL 1: without a dated, valid agreement the employer could not prove the regime during an
     * inspection, so these fields are required as soon as the recording is not the detailed one.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isSimplified = $this->input('mode') === TimeRecordingMode::Simplified->value;
        $isWaived = $this->input('mode') === TimeRecordingMode::Waived->value;

        return [
            'mode' => ['required', Rule::enum(TimeRecordingMode::class)],
            'agreement_date' => [Rule::requiredIf($isSimplified || $isWaived), 'nullable', 'date_format:Y-m-d'],
            'agreement_valid_from' => [Rule::requiredIf($isSimplified || $isWaived), 'nullable', 'date_format:Y-m-d', Rule::when($this->filled('agreement_date'), 'after_or_equal:agreement_date')],
            'agreement_valid_to' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('agreement_valid_from'), 'after_or_equal:agreement_valid_from')],
            'agreement_basis' => [Rule::requiredIf($isSimplified), 'nullable', Rule::in(['collective', 'individual'])],
            'autonomy_percent' => [Rule::requiredIf($isSimplified), 'nullable', 'integer', 'between:'.SwissWorkingTimeRules::SIMPLIFIED_MINIMUM_AUTONOMY_PERCENT.',100'],
            'collective_agreement_reference' => [Rule::requiredIf($isWaived), 'nullable', 'string', 'max:120'],
            'annual_gross_salary' => [Rule::requiredIf($isWaived), 'nullable', 'numeric', 'decimal:0,2', Rule::when($isWaived, 'gt:'.SwissWorkingTimeRules::WAIVER_MINIMUM_ANNUAL_SALARY)],
            'legal_max_weekly_hours' => ['required', 'integer', Rule::in([45, 50])],
            'weekly_hours' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:168'],
            'work_days_per_week' => ['required', 'integer', 'between:1,7'],
            'hire_date' => ['nullable', 'date_format:Y-m-d'],
            'termination_date' => ['nullable', 'date_format:Y-m-d', Rule::when($this->filled('hire_date'), 'after_or_equal:hire_date')],
            'canton' => ['nullable', Rule::in(SwissHolidayCalendar::CANTONS)],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    /**
     * An individual agreement for the simplified recording is allowed only below 50 employees (art. 73b cpv. 2 OLL 1).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('mode') === TimeRecordingMode::Simplified->value
                && $this->input('agreement_basis') === 'individual'
                && $this->companySize() >= SwissWorkingTimeRules::SIMPLIFIED_INDIVIDUAL_AGREEMENT_EMPLOYEE_LIMIT) {
                $validator->errors()->add('agreement_basis', __('validation.in', ['attribute' => 'agreement_basis']));
            }
        }];
    }

    private function companySize(): int
    {
        return max(
            Employee::query()->whereNull('termination_date')->count(),
            DB::table('tenant_user')->where('tenant_id', app(TenantContext::class)->getTenantId())->count(),
        );
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('canton'))) {
            $this->merge(['canton' => strtoupper(trim($this->input('canton')))]);
        }
    }
}
