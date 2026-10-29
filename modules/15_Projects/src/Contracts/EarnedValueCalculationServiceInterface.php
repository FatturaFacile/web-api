<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectFinancialSummary;

interface EarnedValueCalculationServiceInterface
{
    public function recalculateEVM(Project $project): ProjectFinancialSummary;
}
