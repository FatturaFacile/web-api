<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\BondedWarehouses\Domain\Enums\DeclarationType;
use Modules\BondedWarehouses\Domain\Support\CustomsCodes;

final class RegisterInboundMrnRequest extends FormRequest
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
            'authorization_id' => ['required', 'uuid', Rule::exists('customs_warehouse_authorizations', 'id')->where('tenant_id', $tenantId)],
            'mrn_number' => ['required', 'string', 'size:18', 'regex:/^\d{2}[A-Z]{2}[A-Z0-9]{14}$/'],
            'mrn_item_number' => ['sometimes', 'integer', 'min:1', 'max:999'],
            'declaration_type' => ['required', Rule::enum(DeclarationType::class)],
            'entry_date' => ['required', 'date'],
            'product_id' => ['required', 'uuid', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'wms_lot_id' => ['nullable', 'uuid', Rule::exists('stock_lots', 'id')->where('tenant_id', $tenantId)],
            'taric_hs_code' => ['required', 'string', 'regex:/^\d{8}(\d{2})?$/'],
            'country_of_origin' => ['required', 'string', 'size:2', 'alpha'],
            'preferential_origin' => ['sometimes', 'boolean'],
            'preferential_agreement_code' => ['nullable', 'string', 'max:30', 'required_if:preferential_origin,true'],
            'initial_quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999'],
            'unit_of_measure' => ['sometimes', 'string', 'max:10'],
            'initial_net_weight_kg' => ['required', 'numeric', 'min:0'],
            'initial_gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'gte:initial_net_weight_kg'],
            'customs_statistical_value' => ['required', 'numeric', 'min:0'],
            'duty_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'vat_rate_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'suspended_duty_amount' => ['nullable', 'numeric', 'min:0'],
            'suspended_vat_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('mrn_number'))) {
            $this->merge(['mrn_number' => CustomsCodes::normalizeMrn($this->input('mrn_number'))]);
        }
    }
}
