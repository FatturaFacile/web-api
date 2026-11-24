<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;
use Modules\Inventory\Models\StockReorderRule;

/**
 * A min/max reorder rule of an item in a warehouse. The levels must be consistent: minimum <= reorder point <= maximum.
 * Every value is the company's: nothing is defaulted except the lead time, which keeps its column default.
 */
final class ReorderRuleRequest extends FormRequest
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
        $creating = $this->isMethod('post');
        $presence = $creating ? 'required' : 'sometimes';

        return [
            'warehouse_id' => [$presence, 'uuid', $this->tenantExists('warehouses')],
            'stock_item_id' => [
                $presence, 'uuid', $this->tenantExists('stock_items'),
                $creating ? Rule::unique('stock_reorder_rules', 'stock_item_id')->where('warehouse_id', $this->input('warehouse_id'))->whereNull('deleted_at') : 'prohibited',
            ],
            'min_stock_level' => [$presence, 'numeric', 'min:0'],
            'reorder_point' => [$presence, 'numeric', 'min:0'],
            'max_stock_level' => [$presence, 'numeric', 'min:0'],
            'economic_order_quantity' => [$presence, 'numeric', 'gt:0'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:3650'],
            'preferred_supplier_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('contacts')],
            'auto_trigger_requisition' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Minimum <= reorder point <= maximum, on the values the rule will have after the change.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $current = $this->route('id') !== null ? StockReorderRule::query()->find($this->route('id')) : null;
                $level = fn (string $field): ?string => $this->has($field) ? (string) $this->input($field) : ($current !== null ? (string) $current->{$field} : null);
                [$min, $point, $max] = [$level('min_stock_level'), $level('reorder_point'), $level('max_stock_level')];

                if ($min !== null && $point !== null && bccomp($min, $point, 4) > 0) {
                    $validator->errors()->add('reorder_point', 'The reorder point cannot be below the minimum stock level.');
                }

                if ($point !== null && $max !== null && bccomp($point, $max, 4) > 0) {
                    $validator->errors()->add('max_stock_level', 'The maximum stock level cannot be below the reorder point.');
                }
            },
        ];
    }
}
