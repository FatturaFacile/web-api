<?php

declare(strict_types=1);

namespace Modules\AssetManagement\DTOs;

/**
 * Completion data of a maintenance work order: costs, downtime, condition and capitalization as improvement.
 */
final readonly class CompleteMaintenanceWorkOrderDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $partsConsumed
     */
    public function __construct(
        public string $workOrderId,
        public string|int|float $downtimeHours = 0.0,
        public string|int|float $laborCost = 0.0,
        public string|int|float $partsCost = 0.0,
        public string|int|float $externalServiceCost = 0.0,
        public bool $capitalizeAsUpgrade = false,
        public string $conditionAfterMaintenance = 'good',
        public ?string $resolutionNotes = null,
        public array $partsConsumed = [],
        public string|int|float|null $meterReading = null
    ) {}
}
