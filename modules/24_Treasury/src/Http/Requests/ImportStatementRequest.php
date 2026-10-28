<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ImportStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'uuid'],
            'statement_number' => ['nullable', 'string', 'max:50'],
            'statement_date' => ['nullable', 'date'],
            'format' => ['nullable', 'string', 'in:CAMT_053,CAMT_052,MT940,MT942,CSV'],
            'opening_balance' => ['nullable', 'numeric'],
            'closing_balance' => ['nullable', 'numeric'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.booking_date' => ['nullable', 'date'],
            'lines.*.value_date' => ['nullable', 'date'],
            'lines.*.amount' => ['required', 'numeric'],
            'lines.*.bank_reference' => ['nullable', 'string', 'max:100'],
            'lines.*.customer_reference' => ['nullable', 'string', 'max:100'],
            'lines.*.counterparty_name' => ['nullable', 'string', 'max:255'],
            'lines.*.counterparty_iban' => ['nullable', 'string', 'max:34'],
            'lines.*.remittance_information' => ['nullable', 'string'],
        ];
    }
}
