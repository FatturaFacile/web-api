<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreHealthExpenseRequest extends FormRequest
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
            'document_type' => ['required', 'in:invoice,commercial_document'],
            'document_id' => ['required', 'uuid'],
            'expense_type' => ['required', 'in:TK,FC,FV,SV,SP,AD,AS,SR,CT,PI,IC,AA'],
            'expense_flag' => ['nullable', 'in:1,2'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'is_advance_payment' => ['nullable', 'boolean'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999.99'],
            'tax_rate' => ['nullable', 'numeric', 'between:0,99.99', 'prohibits:vat_nature'],
            'vat_nature' => ['nullable', 'string', 'max:4'],
            'device_number' => ['nullable', 'string', 'regex:/^[0-9]{1,3}$/'],
            'patient_fiscal_code' => ['nullable', 'required_unless:patient_opposition,true,1', 'string', 'size:16', 'regex:/^[A-Za-z0-9]{16}$/'],
            'patient_opposition' => ['nullable', 'boolean'],
            'payment_traceable' => ['required', 'boolean'],
        ];
    }
}
