<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * A change order to register. When lines are given, the cost impact is the sum of their cost deltas and the revenue
 * impact the sum of their revenue deltas.
 */
final readonly class ChangeOrderDTO
{
    /**
     * @param  array<int, array{cost_category: string, description: string, quantity_delta: string|int|float, unit_cost_delta: string|int|float, total_cost_delta: string|int|float, revenue_delta?: string|int|float|null}>  $lines
     */
    public function __construct(
        public string $projectId,
        public string $orderNumber,
        public string $title,
        public string $scopeDescription,
        public string $costImpactAmount,
        public string $revenueImpactAmount = '0',
        public int $scheduleImpactDays = 0,
        public array $lines = [],
        public ?string $userId = null
    ) {}
}
