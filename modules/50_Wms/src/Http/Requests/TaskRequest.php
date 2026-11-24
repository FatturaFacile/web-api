<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Wms\Models\WmsTask;

/**
 * Payload to create or change a task.
 */
final class TaskRequest extends WmsFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'stock_item_id' => ['nullable', 'uuid', $this->ownRow('stock_items')],
            'source_bin_id' => ['nullable', 'uuid', $this->ownRow('warehouse_bins')],
            'destination_bin_id' => ['nullable', 'uuid', $this->ownRow('warehouse_bins')],
            'planned_quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'assigned_to' => ['nullable', 'uuid'],
            'reference' => ['nullable', 'string', 'max:100'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? [
            'warehouse_id' => ['required', 'uuid', $this->ownRow('warehouses')],
            'task_type' => ['required', Rule::in(WmsTask::TaskTypes)],
            ...$rules,
        ] : $rules;
    }
}
