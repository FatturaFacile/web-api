<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\ProjectBudgetDTO;
use Modules\Projects\Models\ProjectBudget;

/**
 * Project budgets: revisions, approval, baselines and rejection.
 */
interface ProjectBudgetServiceInterface
{
    /**
     * Saves the budget as a draft revision (or sends it to the approval workflow when configured).
     */
    public function createOrUpdateBudget(ProjectBudgetDTO $dto): ProjectBudget;

    /**
     * Re-freezes an approved budget as a new baseline.
     */
    public function baselineBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget;

    /**
     * Approves a draft or pending budget and freezes its baseline.
     */
    public function approveBudget(ProjectBudget $budget, ?string $approvedBy = null): ProjectBudget;

    /**
     * Rejects a draft or pending budget.
     */
    public function rejectBudget(ProjectBudget $budget, string $rejectedBy, ?string $reason = null): ProjectBudget;
}
