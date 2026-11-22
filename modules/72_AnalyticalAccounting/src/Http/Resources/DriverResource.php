<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Resources;

/**
 * API representation of an allocation driver.
 */
final class DriverResource extends AttributeResource
{
    protected function fields(): array
    {
        return ['id', 'code', 'name', 'driver_type', 'unit', 'is_system', 'is_active'];
    }
}
