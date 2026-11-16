<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\Payroll\SwissCanton;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;

final class StoreWithholdingTariffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', WithholdingTariff::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'canton' => ['required', 'string', Rule::in(SwissCanton::values())],
            'tariff_code' => ['required', 'string', 'regex:/^[A-Z]\d{1,2}[YN]$/'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'brackets' => ['required', 'array', 'min:1'],
            'brackets.*.income_from' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'brackets.*.income_to' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'brackets.*.rate_percent' => ['required', 'numeric', 'decimal:0,4', 'between:0,100'],
            'brackets.*.fixed_amount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
        ];
    }
}
