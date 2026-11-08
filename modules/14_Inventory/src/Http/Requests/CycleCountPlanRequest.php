<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * A cycle count plan: the warehouse, the ABC class it counts (none = every item), how often, and whether its sessions
 * freeze the stock they count.
 */
final class CycleCountPlanRequest extends FormRequest
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
        $presence = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'warehouse_id' => [$presence, 'uuid', $this->tenantExists('warehouses')],
            'name' => [$presence, 'string', 'max:150'],
            'abc_class' => ['sometimes', 'nullable', 'string', 'in:A,B,C'],
            'interval_days' => [$presence, 'integer', 'min:1', 'max:3660'],
            'freezes_stock' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'next_run_on' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
