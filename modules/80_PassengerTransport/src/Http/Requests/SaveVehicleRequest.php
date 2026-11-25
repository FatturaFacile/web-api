<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\PassengerTransport\Domain\Enums\VehicleCategory;
use Modules\PassengerTransport\Http\Requests\Concerns\ScopesToTenant;

final class SaveVehicleRequest extends FormRequest
{
    use ScopesToTenant;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $ignore = $this->route('id');

        return [
            'license_plate' => [$required, 'string', 'max:20', $this->tenantUnique('ptr_fleet_vehicles', 'license_plate')->ignore($ignore)],
            'vehicle_category' => [$required, Rule::in(VehicleCategory::values())],
            'gross_weight_tons' => ['sometimes', 'numeric', 'min:0', 'max:60'],
            'passenger_capacity' => [$required, 'integer', 'min:1', 'max:80'],
            'has_digital_tachograph' => ['sometimes', 'boolean'],
            'psva_subject' => ['sometimes', 'boolean'],
            'ncc_authorization_number' => ['nullable', 'string', 'max:60'],
            'ncc_issuing_municipality' => ['nullable', 'string', 'max:120'],
            'odometer_km' => ['sometimes', 'integer', 'min:0'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
