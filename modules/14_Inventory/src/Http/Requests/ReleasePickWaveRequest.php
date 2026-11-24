<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Releasing a picking wave: the warehouse, the sales orders it serves and the staging bin the picks go to.
 */
final class ReleasePickWaveRequest extends FormRequest
{
    use ValidatesTenantRecords;

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
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'staging_bin_id' => ['required', 'uuid', $this->tenantExists('warehouse_bins')],
            'sales_order_ids' => ['required', 'array', 'min:1', 'max:200'],
            'sales_order_ids.*' => ['required', 'uuid', 'distinct', $this->tenantExists('sales_orders')],
            'assigned_picker_user_id' => ['nullable', 'uuid', 'exists:tenant_user,user_id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
