<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;

/**
 * Assigns a work order to a technician for a time window.
 */
final readonly class DispatchTechnicianWorkOrderCommand
{
    public function __construct(
        public string $workOrderId,
        public string $technicianId,
        public CarbonImmutable $scheduledStart,
        public CarbonImmutable $scheduledEnd,
        public ?string $actorUserId = null,
    ) {}
}
