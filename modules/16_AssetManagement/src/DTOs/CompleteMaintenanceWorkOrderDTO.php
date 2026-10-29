<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

final readonly class CompleteMaintenanceWorkOrderDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $partsConsumed
     */
    public function __construct(
        public string $workOrderId,
        public float $downtimeHours = 0.0,
        public float $laborCost = 0.0,
        public float $partsCost = 0.0,
        public float $externalServiceCost = 0.0,
        public bool $capitalizeAsUpgrade = false,
        public string $conditionAfterMaintenance = 'good',
        public ?string $resolutionNotes = null,
        public array $partsConsumed = []
    ) {}
}
