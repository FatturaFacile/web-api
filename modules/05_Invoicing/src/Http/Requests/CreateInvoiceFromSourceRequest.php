<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a caller may state when an invoice is generated from a sales order or a delivery note. The customer, the lines,
 * the source documents and the number always come from the source document and the numbering service.
 */
final class CreateInvoiceFromSourceRequest extends FormRequest
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
            'date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:4'],
            'payment_terms' => ['nullable', 'string', 'max:4'],
            'bank_name' => ['nullable', 'string'],
            'bank_iban' => ['nullable', 'string', 'max:34'],
        ];
    }
}
