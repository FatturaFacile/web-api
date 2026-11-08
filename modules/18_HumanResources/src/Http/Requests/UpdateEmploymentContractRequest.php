<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The descriptive data of the active contract; pay, hours and start change through a new contract.
 */
final class UpdateEmploymentContractRequest extends FormRequest
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
            'job_title' => ['sometimes', 'string', 'max:150'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'probation_end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'collective_agreement' => ['sometimes', 'nullable', 'string', 'max:120'],
            'collective_agreement_id' => ['sometimes', 'nullable', 'uuid', TenantScopedRules::existsInTable('payroll_collective_agreements')],
            'collective_agreement_level_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('payroll_collective_agreement_levels', 'id')->where('agreement_id', $this->input('collective_agreement_id')),
            ],
            'level_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'terms' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
