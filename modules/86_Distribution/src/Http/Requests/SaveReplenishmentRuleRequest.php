<?php

declare(strict_types=1);

namespace Modules\Distribution\Http\Requests;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Distribution\Domain\Enums\ReplenishmentPolicy;
use Modules\Distribution\Http\Requests\Concerns\ScopesToTenant;

/**
 * Replenishment policy of an item in a warehouse.
 */
final class SaveReplenishmentRuleRequest extends FormRequest
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
        $ignore = $this->route('id');

        return [
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
            'stock_item_id' => [
                'required', 'uuid', $this->tenantExists('stock_items'),
                Rule::unique('distribution_replenishment_rules', 'stock_item_id')
                    ->where('tenant_id', app(TenantContext::class)->getTenantId())
                    ->where('warehouse_id', $this->input('warehouse_id'))
                    ->whereNull('deleted_at')
                    ->ignore(is_string($ignore) ? $ignore : null),
            ],
            'policy' => ['required', 'string', Rule::in(ReplenishmentPolicy::values())],
            'source_warehouse_id' => ['nullable', 'uuid', 'different:warehouse_id', $this->tenantExists('warehouses')],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0', 'gte:reorder_point'],
            'fixed_quantity' => ['nullable', 'numeric', 'min:0', 'required_if:policy,reorder_point_fixed_qty'],
            'min_transfer_qty' => ['nullable', 'numeric', 'min:0'],
            'transfer_multiple' => ['nullable', 'numeric', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'review_cycle_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_if:policy,periodic_review'],
            'target_days_of_supply' => ['nullable', 'integer', 'min:1', 'max:365'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
