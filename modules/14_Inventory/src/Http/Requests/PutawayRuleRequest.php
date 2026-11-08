<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;
use Modules\Inventory\Models\PutawayRule;
use Modules\Inventory\Models\WarehouseBin;

/**
 * A putaway rule: for an item, an item category or every item of a warehouse, where its stock goes (a bin, a zone or
 * a bin type) and how the bin is chosen. Creating needs the warehouse and a target; updating may change any field.
 */
final class PutawayRuleRequest extends FormRequest
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
            'stock_item_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('stock_items')],
            'category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('stock_item_categories')],
            'target_zone_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('warehouse_zones')],
            'target_bin_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('warehouse_bins')],
            'bin_type' => ['sometimes', 'nullable', 'string', Rule::in(WarehouseBin::PUTAWAY_BIN_TYPES)],
            'strategy' => [$presence, 'string', Rule::in(PutawayRule::STRATEGIES)],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A new rule must say where the stock goes.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->isMethod('post') && ! $this->filled('target_bin_id') && ! $this->filled('target_zone_id') && ! $this->filled('bin_type')) {
                    $validator->errors()->add('target_bin_id', 'A putaway rule needs a target bin, a target zone or a bin type.');
                }
            },
        ];
    }
}
