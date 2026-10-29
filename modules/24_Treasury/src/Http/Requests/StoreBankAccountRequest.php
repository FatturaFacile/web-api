<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:150'],
            'branch_name' => ['nullable', 'string', 'max:150'],
            'iban' => ['required', 'string', 'max:34'],
            'bic_swift' => ['nullable', 'string', 'max:11'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3'],
            'account_type' => ['nullable', 'string', 'in:checking,savings,escrow,credit_line,cash_pool_header'],
            'gl_account_id' => ['nullable', 'uuid'],
            'overdraft_limit' => ['nullable', 'numeric', 'min:0'],
            'opening_balance' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
