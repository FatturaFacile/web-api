<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class CompleteRmaRepairRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'replaced_components' => ['nullable', 'array'],
            'replaced_components.*.old_component_id' => ['required', 'uuid'],
            'replaced_components.*.new_component_serial' => ['required', 'string', 'max:100'],
            'replaced_components.*.component_product_id' => ['nullable', 'uuid'],
            'installed_firmware_version' => ['nullable', 'string', 'max:60'],
            'test_notes' => ['nullable', 'string'],
        ];
    }
}
