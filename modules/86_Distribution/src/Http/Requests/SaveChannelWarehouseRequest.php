<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\AllocationMode;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * How a sales channel draws on a warehouse.
 */
final class SaveChannelWarehouseRequest extends FormRequest
{
    use ScopesToTenant;

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
            'allocation_mode' => ['required', 'string', Rule::in(AllocationMode::values())],
            'allocation_percentage' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:allocation_mode,percentage'],
            'fixed_reserve_qty' => ['nullable', 'numeric', 'min:0', 'required_if:allocation_mode,fixed_reserve'],
            'priority_rank' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'include_in_transit' => ['sometimes', 'boolean'],
            'exclude_consignment' => ['sometimes', 'boolean'],
            'exclude_3pl' => ['sometimes', 'boolean'],
            'safety_buffer_qty' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
