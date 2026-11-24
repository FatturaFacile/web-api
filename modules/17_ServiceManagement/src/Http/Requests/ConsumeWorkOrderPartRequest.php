<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the issue of a stocked part to a work order. The item number is the SKU of a product of the company.
 */
final class ConsumeWorkOrderPartRequest extends FormRequest
{
    use TenantScopedRules;

    /**
     * Access is decided by the module.access:service middleware.
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
        return [
            'item_number' => ['required', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'description' => ['nullable', 'string', 'max:255'],
            'warehouse_id' => ['nullable', 'uuid', $this->tenantOwns('warehouses')],
        ];
    }
}
