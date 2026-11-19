<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CustomsOriginType;
use App\Enums\Incoterm;
use App\Http\Requests\Concerns\ValidatesItalianFiscalFields;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    use ValidatesItalianFiscalFields;

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
            'contact_id' => [
                'required',
                'uuid',
                Rule::exists('contacts', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id')),
            ],
            'invoice_type' => ['required', Rule::in(['standard', 'sdi_electronic', 'ch_qr_bill', 'credit_note', 'proforma'])],
            'invoice_number' => ['exclude'],
            'related_invoice_id' => [
                'nullable',
                'uuid',
                Rule::exists('invoices', 'id')->where('tenant_id', $tenantId),
            ],
            'date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:date'],
            'currency' => ['required', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'payment_term_id' => ['nullable', 'uuid', Rule::exists('payment_terms', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'jurisdiction' => ['required', Rule::in(['IT', 'CH'])],
            'split_payment' => ['nullable', 'boolean'],
            'reverse_charge' => ['nullable', 'boolean'],
            'sdi_simplified' => ['nullable', 'boolean'],
            'sdi_vehicle' => ['nullable', 'array'],
            'sdi_vehicle.date' => ['required_with:sdi_vehicle', 'date'],
            'sdi_vehicle.mileage' => ['nullable', 'string', 'max:15'],
            'sdi_extra' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))],
            'items.*.description' => ['required', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'items.*.sdi_data' => ['nullable', 'array'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ...$this->italianFiscalRules($tenantId),
            'items.*.taric_code' => ['nullable', 'string', 'regex:/^\d{6}(\d{2}(\d{2})?)?$/'],
            'items.*.country_of_origin' => ['nullable', 'string', 'size:2'],
            'items.*.origin_type' => ['nullable', Rule::enum(CustomsOriginType::class)],
            'items.*.made_in_label' => ['nullable', 'string', 'max:255'],
            'free_trade_agreement_id' => ['nullable', 'uuid', Rule::exists('free_trade_agreements', 'id')],
            'origin_declaration_text' => ['nullable', 'string', 'max:2000'],
            'duty_exemption_eligible' => ['nullable', 'boolean'],
            'incoterm' => ['nullable', Rule::enum(Incoterm::class)],
            'incoterm_place' => ['nullable', 'string', 'max:100'],
            'freight_amount' => ['nullable', 'numeric', 'min:0'],
            'insurance_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
