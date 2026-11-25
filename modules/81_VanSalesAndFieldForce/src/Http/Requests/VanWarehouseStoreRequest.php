<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;

final class VanWarehouseStoreRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:160'],
            'license_plate' => ['required', 'string', 'max:32'],
            'vehicle_make_model' => ['nullable', 'string', 'max:120'],
            'driver_user_id' => ['nullable', 'uuid'],
            'driver_name' => ['nullable', 'string', 'max:140'],
            'depot_warehouse_id' => ['nullable', 'uuid'],
            'has_cold_chain' => ['boolean'],
            'min_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:30'],
            'max_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:30'],
            'current_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'invoice_sectional_code' => ['nullable', 'string', 'max:32'],
            'ddt_sectional_code' => ['nullable', 'string', 'max:32'],
            'receipt_sectional_code' => ['nullable', 'string', 'max:32'],
            'fiscal_year' => ['nullable', 'integer', 'min:2024', 'max:2035'],
            'printer_profile' => ['nullable', Rule::in(ThermalPrinterProfile::values())],
            'printer_brand' => ['nullable', 'string', 'max:40'],
            'printer_mac_address' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(VanWarehouseStatus::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
