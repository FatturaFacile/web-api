<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\FieldService\Commands;

use Carbon\CarbonImmutable;

/**
 * Generates the preventive maintenance work orders that are due (or due within the lead time) for a tenant.
 */
final readonly class GeneratePreventiveMaintenanceWorkOrdersCommand
{
    public function __construct(
        public string $tenantId,
        public ?CarbonImmutable $asOf = null,
        public ?string $actorUserId = null,
    ) {}
}
