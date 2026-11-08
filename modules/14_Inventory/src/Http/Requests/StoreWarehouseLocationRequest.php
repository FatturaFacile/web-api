<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Models\WarehouseBin;

final class StoreWarehouseLocationRequest extends FormRequest
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
            'zone_code' => ['required', 'string', 'max:50'],
            'zone_name' => ['nullable', 'string', 'max:100'],
            'zone_type' => ['nullable', 'string', 'in:receiving,storage,picking,packing,shipping,quarantine,transit'],
            'aisle_code' => ['required', 'string', 'max:50'],
            'rack_code' => ['required', 'string', 'max:50'],
            'bin_code' => ['required', 'string', 'max:50'],
            'barcode' => ['required', 'string', 'max:100', Rule::unique('warehouse_bins', 'barcode')->where('tenant_id', app(TenantContext::class)->getTenantId())],
            'pick_sequence' => ['nullable', 'integer', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_volume_cbm' => ['nullable', 'numeric', 'min:0'],
            'max_quantity' => ['nullable', 'numeric', 'gt:0'],
            'bin_type' => ['nullable', 'string', Rule::in(WarehouseBin::BIN_TYPES)],
        ];
    }
}
