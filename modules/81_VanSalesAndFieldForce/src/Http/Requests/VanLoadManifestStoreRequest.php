<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VanLoadManifestStoreRequest extends FormRequest
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
            'van_warehouse_id' => ['required', 'uuid'],
            'load_date' => ['nullable', 'date'],
            'route_code' => ['nullable', 'string', 'max:40'],
            'depot_warehouse_id' => ['nullable', 'uuid'],
            'global_ddt_causal' => ['nullable', 'string', 'max:80'],
            'departure_temp_celsius' => ['nullable', 'numeric', 'min:-40', 'max:40'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:80'],
            'lines.*.expiry_date' => ['nullable', 'date'],
            'lines.*.is_variable_weight' => ['nullable', 'boolean'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.packages_count' => ['nullable', 'integer', 'min:1'],
            'lines.*.gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'empties_loaded' => ['nullable', 'array'],
        ];
    }
}
