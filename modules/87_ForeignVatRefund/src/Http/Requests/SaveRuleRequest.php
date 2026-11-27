<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;
use Modules\ForeignVatRefund\Domain\Enums\RegimeType;

/**
 * A company override of one cell of the reciprocity and deductibility matrix.
 */
final class SaveRuleRequest extends FormRequest
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
            'home_country_code' => ['required', 'string', 'size:2', 'alpha'],
            'refund_country_code' => ['required', 'string', 'size:2', 'alpha', 'different:home_country_code'],
            'regime_type' => ['required', Rule::enum(RegimeType::class)],
            'has_reciprocity' => ['required', 'boolean'],
            'expense_category_code' => ['required', 'integer', Rule::in(ExpenseCategory::codes())],
            'sub_code' => ['nullable', 'string', 'max:20'],
            'deductible_percentage' => ['required', 'numeric', 'between:0,100'],
            'min_annual_amount' => ['nullable', 'numeric', 'min:0'],
            'min_quarterly_amount' => ['nullable', 'numeric', 'min:0'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'filing_deadline_month' => ['required', 'integer', 'between:1,12'],
            'filing_deadline_day' => ['required', 'integer', 'between:1,31'],
            'vat_rates' => ['nullable', 'array', 'max:12'],
            'vat_rates.*' => ['numeric', 'between:0,100'],
            'requires_fiscal_representative' => ['sometimes', 'boolean'],
            'requires_taxable_status_certificate' => ['sometimes', 'boolean'],
            'notes_key' => ['nullable', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $upper = [];

        foreach (['home_country_code', 'refund_country_code', 'currency_code'] as $field) {
            if (is_string($this->input($field))) {
                $upper[$field] = strtoupper($this->input($field));
            }
        }

        $this->merge($upper);
    }
}
