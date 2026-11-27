<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;

/**
 * A foreign VAT item entered by hand.
 */
final class SaveLedgerEntryRequest extends FormRequest
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
            'document_number' => ['required', 'string', 'max:80'],
            'document_date' => ['required', 'date'],
            'supplier_name' => ['nullable', 'string', 'max:190'],
            'supplier_vat_number' => ['nullable', 'string', 'max:40'],
            'supplier_country_code' => ['required', 'string', 'size:2', 'alpha'],
            'expense_category_code' => ['required', 'integer', Rule::in(ExpenseCategory::codes())],
            'net_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'foreign_vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'foreign_vat_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'exchange_rate_to_base' => ['nullable', 'numeric', 'gt:0'],
            'vat_booked_on' => ['nullable', Rule::in(['input_vat', 'expense'])],
            'post_to_ledger' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $upper = [];

        foreach (['supplier_country_code', 'currency_code'] as $field) {
            if (is_string($this->input($field))) {
                $upper[$field] = strtoupper($this->input($field));
            }
        }

        $this->merge($upper);
    }
}
