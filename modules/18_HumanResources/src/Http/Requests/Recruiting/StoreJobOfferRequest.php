<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\ContractType;
use Modules\HumanResources\Enums\EmploymentType;

final class StoreJobOfferRequest extends FormRequest
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
            'proposed_salary' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', Rule::in(['EUR', 'CHF'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'employment_type' => ['nullable', 'string', Rule::in(EmploymentType::values())],
            'contract_type' => ['nullable', 'string', Rule::in(ContractType::values())],
            'weekly_hours' => ['nullable', 'numeric', 'min:1', 'max:60'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:12'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'send' => ['nullable', 'boolean'],
        ];
    }
}
