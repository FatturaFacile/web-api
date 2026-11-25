<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueDdtInvoiceRequest extends FormRequest
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
            'dossier_id' => ['nullable', 'uuid'],
            'sales_order_id' => ['nullable', 'uuid'],
            'related_document_id' => ['nullable', 'uuid'],
            'document_kind' => ['required', Rule::in(['ddt_it', 'lieferschein_ch', 'immediate_invoice', 'deferred_summary_invoice', 'credit_note', 'debit_note'])],
            'document_number' => ['nullable', 'string', 'max:40'],
            'grouped_ddt_numbers' => ['nullable', 'array'],
            'grouped_ddt_numbers.*' => ['string', 'max:40'],
            'is_drop_shipping' => ['nullable', 'boolean'],
            'drop_ship_supplier_name' => ['nullable', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'size:3'],
            'total_net' => ['nullable', 'numeric', 'min:0'],
            'total_tax' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'issue_date' => ['nullable', 'date'],
        ];
    }
}
