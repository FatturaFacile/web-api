<?php

declare(strict_types=1);

namespace Modules\GreenCare\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\GreenCare\Domain\Enums\BillingMode;
use Modules\GreenCare\Domain\Enums\InstallmentFrequency;
use Modules\GreenCare\Domain\Enums\ServiceType;

final class SaveContractRequest extends GreenCareRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'contract_number' => ['sometimes', 'nullable', 'string', 'max:40', $this->uniqueInTenant('seasonal_maintenance_contracts', 'contract_number', is_string($id) ? $id : null)],
            'site_id' => ['required', 'uuid', $this->inTenant('green_space_sites')],
            'customer_id' => ['required', 'uuid', $this->inTenant('contacts')],
            'condominium_administrator_id' => ['nullable', 'uuid', $this->inTenant('contacts')],
            'season_start_date' => ['required', 'date'],
            'season_end_date' => ['required', 'date', 'after:season_start_date'],
            'billing_mode' => ['required', Rule::in(BillingMode::values())],
            'included_passages_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'extra_passage_unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'fixed_fee_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'installment_frequency' => ['nullable', Rule::in(InstallmentFrequency::values())],
            'time_and_material_hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'snow_removal_standby_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'snow_trigger_threshold_cm' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:snow_removal_standby_fee'],
            'included_services' => ['nullable', 'array'],
            'included_services.*' => ['string', Rule::in(array_map(static fn (ServiceType $type): string => $type->value, ServiceType::cases()))],
            'auto_bill_extra_passages' => ['sometimes', 'boolean'],
            'apply_condominium_withholding' => ['sometimes', 'boolean'],
            'is_condominium' => ['sometimes', 'boolean'],
            'condominium_fiscal_code' => ['nullable', 'string', 'max:20', 'required_if:is_condominium,true'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
