<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;

final class UpdateSelfInvoiceRequest extends FormRequest
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
            'document_type' => ['sometimes', Rule::in(SelfInvoiceDocumentType::values())],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'supplier_name' => ['sometimes', 'string', 'max:200'],
            'supplier_vat_number' => ['nullable', 'string', 'max:40'],
            'supplier_fiscal_code' => ['nullable', 'string', 'max:30'],
            'supplier_country_code' => ['sometimes', 'string', 'size:2'],
            'supplier_address' => ['nullable', 'array'],
            'original_invoice_number' => ['nullable', 'string', 'max:80'],
            'original_invoice_date' => ['nullable', 'date'],
            'original_sdi_identifier' => ['nullable', 'string', 'max:64'],
            'recipient_code' => ['sometimes', 'string', 'size:7'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
