<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

use Modules\AnalyticalAccounting\Models\ApportionmentRun;

/**
 * API representation of an apportionment run with its trace.
 *
 * @property ApportionmentRun $resource
 */
final class RunResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'apportionment_schedule_id', 'period_key', 'period_start', 'period_end', 'status', 'total_pool', 'total_allocated', 'total_unallocated', 'warnings', 'consolidated_at', 'reversed_at', 'reversal_reason'];
    }

    protected function extra(): array
    {
        $out = [];
        if ($this->resource->relationLoaded('steps')) {
            $out['steps'] = $this->resource->steps->map(static fn ($s): array => $s->only(['step_order', 'source_center_id', 'driver_type', 'status', 'pool_amount', 'allocated_amount', 'driver_total', 'warnings']))->values();
        }
        if ($this->resource->relationLoaded('allocations')) {
            $out['allocations'] = $this->resource->allocations->map(static fn ($a): array => $a->only(['id', 'step_order', 'source_center_id', 'target_center_id', 'driver_type', 'driver_value', 'driver_total', 'share_ratio', 'allocated_amount', 'rounding_adjustment', 'analytical_entry_id']))->values();
        }

        return $out;
    }
}
