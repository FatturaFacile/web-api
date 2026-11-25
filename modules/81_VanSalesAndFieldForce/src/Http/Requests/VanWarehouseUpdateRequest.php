<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanWarehouseStatus;

final class VanWarehouseUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:160'],
            'driver_name' => ['nullable', 'string', 'max:140'],
            'has_cold_chain' => ['sometimes', 'boolean'],
            'min_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:30'],
            'max_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:30'],
            'printer_profile' => ['sometimes', Rule::in(ThermalPrinterProfile::values())],
            'printer_brand' => ['sometimes', 'string', 'max:40'],
            'printer_mac_address' => ['nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(VanWarehouseStatus::values())],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
