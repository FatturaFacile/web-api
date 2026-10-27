<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
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
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'tenant_id' => ['nullable', 'uuid', 'exists:tenants,id'],
            'contact_id' => [
                'required',
                'uuid',
                Rule::exists('contacts', 'id')->where('tenant_id', $tenantId),
            ],
            'invoice_type' => ['required', Rule::in(['standard', 'sdi_electronic', 'ch_qr_bill', 'credit_note', 'proforma'])],
            'invoice_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('invoices', 'invoice_number')->where('tenant_id', $tenantId),
            ],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'jurisdiction' => ['required', Rule::in(['IT', 'CH'])],
            'split_payment' => ['nullable', 'boolean'],
            'reverse_charge' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
