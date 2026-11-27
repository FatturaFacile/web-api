<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Query filters of the distribution lists (all optional).
 */
final class DistributionFilterRequest extends FormRequest
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
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', 'max:30'],
            'proposal_type' => ['sometimes', 'nullable', 'string', Rule::in(['replenishment', 'balancing'])],
            'direction' => ['sometimes', 'nullable', 'string', Rule::in(['out', 'in'])],
            'warehouse_id' => ['sometimes', 'nullable', 'uuid'],
            'source_warehouse_id' => ['sometimes', 'nullable', 'uuid'],
            'destination_warehouse_id' => ['sometimes', 'nullable', 'uuid'],
            'stock_item_id' => ['sometimes', 'nullable', 'uuid'],
            'lot_id' => ['sometimes', 'nullable', 'uuid'],
            'sales_channel_id' => ['sometimes', 'nullable', 'uuid'],
            'run_id' => ['sometimes', 'nullable', 'uuid'],
            'overdue' => ['sometimes', 'boolean'],
            'expected_to' => ['sometimes', 'nullable', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }
}
