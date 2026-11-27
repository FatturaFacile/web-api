<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;

final class StoreSelfInvoiceRequest extends FormRequest
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
            'passive_invoice_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
            'document_type' => ['required', Rule::in(SelfInvoiceDocumentType::values())],
            'transaction_nature' => ['nullable', Rule::in(TransactionNature::values())],
            'override_reason' => ['nullable', 'string', 'max:500'],
            'regularization_trigger' => ['nullable', 'string', 'in:omitted_4_months,irregular_30_days,manual'],
            'integration_date' => ['required', 'date'],
            'operation_date' => ['nullable', 'date'],
            'receipt_date' => ['nullable', 'date'],
            'supplier_country_code' => ['required', 'string', 'size:2'],
            'supplier_name' => ['required', 'string', 'max:200'],
            'supplier_vat_number' => ['nullable', 'string', 'max:40'],
            'supplier_fiscal_code' => ['nullable', 'string', 'max:30'],
            'supplier_address' => ['nullable', 'array'],
            'original_invoice_number' => ['nullable', 'string', 'max:80'],
            'original_invoice_date' => ['nullable', 'date'],
            'original_sdi_identifier' => ['nullable', 'string', 'max:64'],
            'original_currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'validate_and_reconcile' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.transaction_nature' => ['nullable', Rule::in(TransactionNature::values())],
            'lines.*.quantity' => ['nullable', 'numeric', 'gt:0'],
            'lines.*.unit_price_original' => ['required', 'numeric', 'min:0'],
            'lines.*.taxable_amount_original' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.vat_nature' => ['nullable', 'string', 'max:10'],
            'lines.*.deductibility_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.normative_reference' => ['nullable', 'string', 'max:190'],
        ];
    }
}
