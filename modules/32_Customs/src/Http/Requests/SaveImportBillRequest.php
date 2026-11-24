<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveImportBillRequest extends FormRequest
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
            'bill_number' => ['required', 'string', 'max:40', Rule::unique('customs_import_bills', 'bill_number')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'bill_date' => ['required', 'date'],
            'declaration_id' => ['nullable', 'uuid', CustomsRules::exists('customs_goods_declarations')],
            'passive_invoice_id' => ['nullable', 'uuid', CustomsRules::exists('passive_invoices')],
            'broker_invoice_reference' => ['nullable', 'string', 'max:50'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'customs_value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'duty_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'ancillary_costs' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'import_vat_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }
}
