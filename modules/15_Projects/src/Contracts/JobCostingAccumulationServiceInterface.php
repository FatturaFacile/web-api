<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectCostEntry;
use Modules\Projects\Models\ProjectFinancialSummary;

interface JobCostingAccumulationServiceInterface
{
    public function recordCost(RecordJobCostDTO $dto): ProjectCostEntry;

    public function rollupProjectCosts(Project $project): ProjectFinancialSummary;

    public function analyzeCostVariance(Project $project): array;
}
