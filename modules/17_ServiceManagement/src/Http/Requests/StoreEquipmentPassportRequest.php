<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class StoreEquipmentPassportRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'serial_number' => ['required', 'string', 'max:100'],
            'stock_serial_number_id' => ['nullable', 'uuid'],
            'product_id' => ['nullable', 'uuid'],
            'current_owner_customer_id' => ['required', 'uuid'],
            'original_sales_invoice_id' => ['nullable', 'uuid'],
            'customer_type' => ['nullable', 'string', 'in:b2b,b2c'],
            'jurisdiction' => ['nullable', 'string', 'in:CH,IT,ch,it'],
            'delivery_date' => ['required', 'date'],
            'contract_warranty_months' => ['nullable', 'integer', 'between:1,60'],
            'contract_clause_reference' => ['nullable', 'string', 'max:150'],
            'installed_firmware_version' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string'],
            'components' => ['nullable', 'array'],
            'components.*.position' => ['required', 'string', 'max:100'],
            'components.*.component_serial' => ['required', 'string', 'max:100'],
            'components.*.component_product_id' => ['nullable', 'uuid'],
            'components.*.role' => ['nullable', 'string', 'max:60'],
        ];
    }
}
