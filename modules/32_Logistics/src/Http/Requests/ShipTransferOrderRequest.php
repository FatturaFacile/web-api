<?php

declare(strict_types=1);

namespace Modules\Logistics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Authorization is enforced per route by the logistics.can middleware (RBAC matrix). */
final class ShipTransferOrderRequest extends FormRequest
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
            'carrier_name' => ['nullable', 'string', 'max:255'],
            'carrier_license_plate' => ['nullable', 'string', 'max:30'],
            'transport_by' => ['nullable', Rule::in(['sender', 'recipient', 'carrier'])],
            'carriage_terms' => ['nullable', Rule::in(['free', 'collect', 'free_with_charge'])],
            'goods_appearance' => ['nullable', 'string', 'max:100'],
            'packages_count' => ['nullable', 'integer', 'min:0'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
