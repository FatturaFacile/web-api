<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Strategy;

/**
 * Size of the entity at the balance sheet date: the three criteria of the dimensional thresholds.
 */
final readonly class EntityMetrics
{
    public function __construct(
        public string $totalAssets,
        public string $revenue,
        public string $averageEmployees,
    ) {}

    /**
     * @return array{total_assets: string, revenue: string, average_employees: string}
     */
    public function toArray(): array
    {
        return ['total_assets' => $this->totalAssets, 'revenue' => $this->revenue, 'average_employees' => $this->averageEmployees];
    }
}
