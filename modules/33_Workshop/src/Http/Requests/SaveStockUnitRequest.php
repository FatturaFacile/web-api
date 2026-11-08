<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a vehicle of the dealer stock; stock numbers are unique within the company.
 */
final class SaveStockUnitRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Always allowed here: who may call the endpoint is decided by the module.access middleware and the policy check
     * of the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';
        $tenantId = app(TenantContext::class)->getTenantId();

        return [
            'stock_number' => [$presence, 'string', 'max:40', Rule::unique('workshop_vehicle_stock', 'stock_number')->where('tenant_id', $tenantId)->ignore($this->route('id'))],
            'condition' => [$presence, 'string', 'in:new,used'],
            'vin' => ['sometimes', 'nullable', 'string', 'max:30'],
            'license_plate' => ['sometimes', 'nullable', 'string', 'max:20'],
            'make' => [$presence, 'string', 'max:50'],
            'model' => [$presence, 'string', 'max:50'],
            'year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2100'],
            'mileage_km' => ['sometimes', 'integer', 'min:0'],
            'supplier_contact_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
            'purchase_price' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0'],
            'list_price' => [$presence, 'decimal:0,2', 'min:0'],
            'currency' => [$presence, 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'in:in_stock,reserved'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
