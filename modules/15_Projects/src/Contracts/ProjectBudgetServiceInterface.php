<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Models\ProjectBudget;

interface ProjectBudgetServiceInterface
{
    public function createOrUpdateBudget(ProjectBudgetDTO $dto): ProjectBudget;

    public function baselineBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget;

    public function approveBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget;
}
