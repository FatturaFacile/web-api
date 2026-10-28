<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\ProjectBudgetServiceInterface;
use Modules\Projects\Models\ProjectBudget;

final readonly class ApproveBudgetRevisionAction
{
    public function __construct(
        private ProjectBudgetServiceInterface $budgetService
    ) {}

    public function execute(ProjectBudget $budget, string $approvedBy): ProjectBudget
    {
        return $this->budgetService->baselineBudget($budget, $approvedBy);
    }
}
