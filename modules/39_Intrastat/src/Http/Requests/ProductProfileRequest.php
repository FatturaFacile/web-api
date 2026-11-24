<?php

declare(strict_types=1);

namespace Modules\Intrastat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Intrastat\Enums\SupplyKind;

final class ProductProfileRequest extends FormRequest
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
            'supply_kind' => ['required', Rule::enum(SupplyKind::class)],
            'cn8_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'net_mass_kg' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'supplementary_units_per_unit' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'service_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'origin_country_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'origin_province_code' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
        ];
    }
}
