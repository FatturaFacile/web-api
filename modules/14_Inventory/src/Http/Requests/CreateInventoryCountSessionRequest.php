<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

final class CreateInventoryCountSessionRequest extends FormRequest
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
            'count_type' => ['required', 'string', 'in:full_physical,cycle_count_abc,spot_check,zone_sweep'],
            'scheduled_date' => ['required', 'date'],
            'warehouse_zone_id' => ['nullable', 'uuid', $this->tenantExists('warehouse_zones')],
            'freezes_stock' => ['sometimes', 'boolean'],
            'bin_ids' => ['sometimes', 'array', 'max:5000'],
            'bin_ids.*' => ['uuid', $this->tenantExists('warehouse_bins')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'effective_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
