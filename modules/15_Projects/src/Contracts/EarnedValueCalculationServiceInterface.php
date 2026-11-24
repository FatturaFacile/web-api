<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectFinancialSummary;

/**
 * Earned value management of projects.
 */
interface EarnedValueCalculationServiceInterface
{
    /**
     * Recomputes PV, EV, AC and the derived indices, stores the snapshot of the day and updates the summary.
     */
    public function recalculateEVM(Project $project, ?string $calculatedBy = null): ProjectFinancialSummary;
}
