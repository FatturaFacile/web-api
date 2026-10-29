<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class ChangeOrderDTO
{
    /**
     * @param  array<int, array{cost_category: string, description: string, quantity_delta: float, unit_cost_delta: float, total_cost_delta: float, revenue_delta?: float}>  $lines
     */
    public function __construct(
        public string $projectId,
        public string $orderNumber,
        public string $title,
        public string $scopeDescription,
        public float $costImpactAmount,
        public float $revenueImpactAmount = 0.00,
        public int $scheduleImpactDays = 0,
        public array $lines = [],
        public ?string $userId = null
    ) {}
}
