<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

final class UpdateEquipmentPassportRequest extends WarrantyRmaFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_owner_customer_id' => ['sometimes', 'required', 'uuid'],
            'original_sales_invoice_id' => ['sometimes', 'nullable', 'uuid'],
            'customer_type' => ['sometimes', 'required', 'string', 'in:b2b,b2c'],
            'jurisdiction' => ['sometimes', 'required', 'string', 'in:CH,IT,ch,it'],
            'delivery_date' => ['sometimes', 'required', 'date'],
            'contract_warranty_months' => ['sometimes', 'nullable', 'integer', 'between:1,60'],
            'contract_clause_reference' => ['sometimes', 'nullable', 'string', 'max:150'],
            'installed_firmware_version' => ['sometimes', 'nullable', 'string', 'max:60'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
