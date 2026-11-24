<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Generates the A/B/C cycle count plans of a warehouse from the cadences of the counting policy.
 */
final class GenerateAbcPlansRequest extends FormRequest
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
            'warehouse_zone_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('warehouse_zones')],
            'freezes_stock' => ['sometimes', 'boolean'],
        ];
    }
}
