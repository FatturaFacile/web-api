<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;

/**
 * Job costing of projects: booking costs, their approval and the roll-up into the financial summary.
 */
interface JobCostingAccumulationServiceInterface
{
    /**
     * Books a cost; a cost already booked for the same source document is returned unchanged.
     */
    public function recordCost(RecordJobCostDTO $dto): ProjectCostEntry;

    /**
     * Approves a pending cost entry so that it counts in the actual cost.
     */
    public function approveEntry(ProjectCostEntry $entry, string $approverUserId): ProjectCostEntry;

    /**
     * Rejects a pending cost entry.
     */
    public function rejectEntry(ProjectCostEntry $entry, string $approverUserId, string $reason): ProjectCostEntry;

    /**
     * Rebuilds the financial summary of the project under a row lock.
     */
    public function rollupProjectCosts(Project $project): ProjectFinancialSummary;

    /**
     * Budget against actual cost by category.
     *
     * @return array<string, array{planned: string, actual: string, delta: string, delta_percentage: string|null}>
     */
    public function analyzeCostVariance(Project $project): array;

    /**
     * Approved actual cost by category.
     *
     * @return array<string, string>
     */
    public function actualCostByCategory(Project $project): array;
}
