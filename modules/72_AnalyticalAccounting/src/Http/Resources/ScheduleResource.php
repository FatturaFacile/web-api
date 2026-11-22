<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

use Modules\AnalyticalAccounting\Models\ApportionmentSchedule;

/**
 * API representation of a step-down schedule.
 *
 * @property ApportionmentSchedule $resource
 */
final class ScheduleResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'name', 'description', 'is_active'];
    }

    protected function extra(): array
    {
        return ['steps' => $this->resource->relationLoaded('steps') ? $this->resource->steps->map(static fn ($s): array => $s->only(['id', 'step_order', 'source_center_id', 'allocation_driver_id', 'driver_type', 'include_descendants', 'fixed_weights_json', 'target_filter_json'])) : []];
    }
}
