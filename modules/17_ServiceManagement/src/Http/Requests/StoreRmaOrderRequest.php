<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Enums\DdtCausale;

final class StoreRmaOrderRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serial_number' => ['required_without:equipment_passport_id', 'nullable', 'string', 'max:100'],
            'equipment_passport_id' => ['required_without:serial_number', 'nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'reported_symptom' => ['required', 'string', 'max:5000'],
            'preauthorized_threshold' => ['nullable', 'numeric', 'min:0'],
            'ddt_causale_outbound' => ['nullable', 'string', Rule::enum(DdtCausale::class)],
            'goodwill' => ['nullable', 'boolean'],
        ];
    }
}
