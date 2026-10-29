<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IntercompanyTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_bank_account_id' => ['required', 'uuid'],
            'destination_bank_account_id' => ['required', 'uuid', 'different:source_bank_account_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transfer_date' => ['nullable', 'date'],
            'intercompany_interest_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'cash_pool_id' => ['nullable', 'uuid'],
        ];
    }
}
