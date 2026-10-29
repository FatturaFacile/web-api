<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePaymentBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'uuid'],
            'batch_reference' => ['nullable', 'string', 'max:50'],
            'payment_method' => ['nullable', 'string', 'in:sepa_credit_transfer,sepa_instant,sepa_direct_debit,swiss_qr_pain001,riba,f24'],
            'execution_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.recipient_name' => ['required', 'string', 'max:255'],
            'lines.*.recipient_iban' => ['required', 'string', 'max:34'],
            'lines.*.recipient_bic' => ['nullable', 'string', 'max:11'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.currency' => ['nullable', 'string', 'size:3'],
            'lines.*.remittance_reference' => ['nullable', 'string', 'max:140'],
            'lines.*.invoice_id' => ['nullable', 'uuid'],
        ];
    }
}
