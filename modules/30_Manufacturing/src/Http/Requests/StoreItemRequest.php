<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

/**
 * Validation of a new manufacturing item.
 */
final class StoreItemRequest extends ManufacturingRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'item_code' => ['required', 'string', 'max:50', Rule::unique('mfg_items', 'item_code')->where('tenant_id', $this->tenantId())->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'item_type' => ['required', 'string', 'in:raw_material,subassembly,finished_good,phantom'],
            'procurement_type' => ['nullable', 'string', 'in:make,buy,subcontract'],
            'stock_item_id' => ['nullable', 'uuid', $this->tenantOwns('stock_items')],
            'product_id' => ['nullable', 'uuid', $this->tenantOwns('products')],
            'default_warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'uom' => ['nullable', 'string', 'max:20'],
            'standard_cost' => ['nullable', ...self::Amount],
            'safety_stock' => ['nullable', ...self::Quantity],
            'min_lot_size' => ['nullable', ...self::Quantity],
            'max_lot_size' => ['nullable', ...self::PositiveQuantity, 'gte:min_lot_size'],
            'lot_size_multiple' => ['nullable', ...self::Quantity],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The active company.
     */
    protected function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }
}
