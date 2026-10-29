<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\Models\ProjectCostEntry;

interface ProjectExpenseServiceInterface
{
    /**
     * @param  array{
     *     project_id: string,
     *     expense_category: string,
     *     description: string,
     *     amount: float,
     *     incurred_date: string,
     *     phase_id?: string|null,
     *     task_id?: string|null,
     *     receipt_document_id?: string|null,
     *     is_billable?: bool,
     *     userId?: string|null
     * } $data
     */
    public function recordExpense(array $data): ProjectCostEntry;

    public function approveExpense(ProjectCostEntry $costEntry, string $approverUserId): ProjectCostEntry;
}
