<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreConsolidationAcquisitionRequest extends FormRequest
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
            'member_tenant_id' => ['required', 'uuid'],
            'acquisition_date' => ['required', 'date'],
            'cost' => ['nullable', 'numeric', 'decimal:0,4'],
            'net_assets_at_acquisition' => ['required', 'numeric', 'decimal:0,4'],
            'acquired_percentage' => ['nullable', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4'],
            'expected_future_charges' => ['nullable', 'numeric', 'gt:0', 'decimal:0,4'],
            'provision_release_years' => ['nullable', 'integer', 'between:1,100', 'required_with:expected_future_charges'],
            'input_currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'recognise_deferred_tax' => ['nullable', 'boolean'],
            'deferred_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'fair_value_adjustments' => ['nullable', 'array', 'max:200'],
            'fair_value_adjustments.*.account_code' => ['required', 'string', 'max:40'],
            'fair_value_adjustments.*.account_name' => ['nullable', 'string', 'max:255'],
            'fair_value_adjustments.*.account_type' => ['required', Rule::in(['asset', 'liability'])],
            'fair_value_adjustments.*.amount' => ['required', 'numeric', 'decimal:0,4'],
            'fair_value_adjustments.*.useful_life_years' => ['nullable', 'integer', 'between:1,100'],
        ];
    }
}
