<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;

/**
 * Query parameters and small bodies of the WMS reports and actions, validated per route: stock valuation at a date,
 * KPIs, barcode lookup, ABC classification run, recount and lot recall.
 */
final class WmsQueryRequest extends FormRequest
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
        $warehouse = ['nullable', 'uuid', $this->tenantExists('warehouses')];

        return match (true) {
            $this->routeIs('inventory.valuation.at-date') => [
                'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'warehouse_id' => $warehouse,
                'valuation_method' => ['nullable', 'string', 'in:fifo,moving_average,standard_cost'],
            ],
            $this->routeIs('inventory.kpis') => [
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
                'warehouse_id' => $warehouse,
                'dead_stock_days' => ['required', 'integer', 'min:1', 'max:3650'],
            ],
            $this->routeIs('inventory.barcode.lookup') => [
                'code' => ['required', 'string', 'max:200'],
                'warehouse_id' => $warehouse,
            ],
            $this->routeIs('inventory.abc-classification.run') => [
                'period_days' => ['required', 'integer', 'min:1', 'max:3650'],
                'a_share' => ['sometimes', 'numeric', 'gt:0', 'lt:100'],
                'b_share' => ['sometimes', 'numeric', 'gt:0', 'lte:100'],
            ],
            $this->routeIs('inventory.counts.recount') => [
                'line_ids' => ['required', 'array', 'min:1', 'max:1000'],
                'line_ids.*' => ['required', 'uuid', 'distinct'],
            ],
            $this->routeIs('inventory.lots.recall') => [
                'quarantine' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }

    /**
     * The B share of an ABC run must be above its A share.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                if ($this->routeIs('inventory.abc-classification.run') && $validator->errors()->isEmpty()
                    && (float) $this->input('b_share', 95) <= (float) $this->input('a_share', 80)) {
                    $validator->errors()->add('b_share', 'The B share must be above the A share.');
                }
            },
        ];
    }
}
