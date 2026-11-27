<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;

final class UpdateLedgerEntryRequest extends FormRequest
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
            'expense_category_code' => ['sometimes', 'integer', Rule::in(ExpenseCategory::codes())],
            'supplier_country_code' => ['sometimes', 'string', 'size:2', 'alpha'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('supplier_country_code'))) {
            $this->merge(['supplier_country_code' => strtoupper($this->input('supplier_country_code'))]);
        }
    }
}
