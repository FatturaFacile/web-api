<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;

final class SaveLineRequest extends FormRequest
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
            'expense_date' => ['required', 'date'],
            'category_id' => ['required', 'uuid'],
            'merchant_name' => ['required', 'string', 'max:200'],
            'merchant_tax_id' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'original_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'original_currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'exchange_rate_date' => ['nullable', 'date'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(PaymentMethod::values())],
            'corporate_card_transaction_id' => ['nullable', 'uuid'],
            'is_traceable_payment' => ['nullable', 'boolean'],
            'project_id' => ['nullable', 'uuid'],
            'billable_to_customer' => ['nullable', 'boolean'],
            'receipt_id' => ['nullable', 'uuid'],
        ];
    }
}
