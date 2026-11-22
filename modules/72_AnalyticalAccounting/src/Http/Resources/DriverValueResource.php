<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

/**
 * API representation of a driver value of a period.
 */
final class DriverValueResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'allocation_driver_id', 'analytical_center_id', 'period_key', 'value'];
    }
}
