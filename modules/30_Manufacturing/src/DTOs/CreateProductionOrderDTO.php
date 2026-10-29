<?php

declare(strict_types=1);

namespace Modules\Manufacturing\DTOs;

final readonly class CreateProductionOrderDTO
{
    public function __construct(
        public string $tenantId,
        public string $mfgItemId,
        public float $targetQuantity,
        public string $plannedStartDate,
        public string $plannedEndDate,
        public ?string $bomId = null,
        public ?string $routingId = null,
        public ?string $projectId = null,
        public ?string $notes = null
    ) {}
}
