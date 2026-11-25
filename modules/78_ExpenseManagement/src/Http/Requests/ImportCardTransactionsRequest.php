<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportCardTransactionsRequest extends FormRequest
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
            'card_account_id' => ['required', 'uuid'],
            'transactions' => ['required', 'array', 'min:1', 'max:1000'],
            'transactions.*.external_id' => ['nullable', 'string', 'max:80'],
            'transactions.*.merchant_name' => ['required', 'string', 'max:200'],
            'transactions.*.transaction_date' => ['required', 'date'],
            'transactions.*.posting_date' => ['nullable', 'date'],
            'transactions.*.amount' => ['required', 'numeric', 'gt:0'],
            'transactions.*.currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
