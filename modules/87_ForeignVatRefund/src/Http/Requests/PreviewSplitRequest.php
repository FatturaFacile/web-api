<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ForeignVatRefund\Domain\Enums\ExpenseCategory;

/**
 * The recoverable and non recoverable split of an amount of foreign VAT (the badge of the invoice and expense screens).
 */
final class PreviewSplitRequest extends FormRequest
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
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'expense_category_code' => ['nullable', 'integer', Rule::in(ExpenseCategory::codes())],
            'description' => ['nullable', 'string', 'max:500'],
            'vat_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
        ];
    }
}
