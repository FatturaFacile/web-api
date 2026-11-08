<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new contract of an employee. The collective agreement and its level are references to the payroll agreement tables
 * of the company (or generic rows); amounts are decimal strings or numbers with at most two decimals.
 */
final class StoreEmploymentContractRequest extends FormRequest
{
    /**
     * Module access is checked by the route middleware.
     */
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
            'contract_number' => ['sometimes', 'string', 'max:60', Rule::unique('hr_employment_contracts', 'contract_number')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'contract_type' => ['required', Rule::in(['permanent', 'fixed_term', 'apprenticeship', 'contractor', 'internship', 'seasonal'])],
            'employment_type' => ['sometimes', Rule::in(['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal'])],
            'job_title' => ['required', 'string', 'max:150'],
            'job_grade_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::exists('hr_job_grades')],
            'collective_agreement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'collective_agreement_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::existsInTable('payroll_collective_agreements')],
            'collective_agreement_level_id' => [
                'sometimes', 'nullable', 'uuid', 'required_with:collective_agreement_id',
                Rule::exists('payroll_collective_agreement_levels', 'id')->where('agreement_id', $this->input('collective_agreement_id')),
            ],
            'level_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'probation_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'weekly_hours' => ['required', 'decimal:0,2', 'gt:0', 'max:80'],
            'fte_percentage' => ['sometimes', 'decimal:0,2', 'gt:0', 'max:100'],
            'base_annual_salary' => ['required', 'decimal:0,2', 'min:0'],
            'hourly_rate' => ['sometimes', 'decimal:0,2', 'min:0'],
            'loaded_hourly_cost' => ['sometimes', 'decimal:0,2', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'terms' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'salary_components' => ['sometimes', 'array', 'max:50'],
            'salary_components.*.wage_type_code' => ['required', 'string', 'max:20'],
            'salary_components.*.description' => ['required', 'string', 'max:150'],
            'salary_components.*.amount' => ['required', 'decimal:0,2'],
            'salary_components.*.frequency' => ['sometimes', Rule::in(['monthly', 'annual'])],
            'salary_components.*.valid_from' => ['sometimes', 'date_format:Y-m-d'],
            'salary_components.*.valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
