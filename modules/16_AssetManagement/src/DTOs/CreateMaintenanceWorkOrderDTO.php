<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Data of a new maintenance work order for an asset.
 */
final readonly class CreateMaintenanceWorkOrderDTO
{
    public function __construct(
        public string $tenantId,
        public string $assetId,
        public string $maintenanceType = 'preventive', // 'preventive', 'corrective', 'condition_based', 'emergency', 'upgrade'
        public string $priority = 'medium', // 'low', 'medium', 'high', 'critical'
        public ?string $scheduledDate = null,
        public ?string $planId = null,
        public ?string $assignedTechnicianId = null,
        public ?string $problemDescription = null,
        public string|int|float $estimatedCost = 0.0
    ) {}
}
