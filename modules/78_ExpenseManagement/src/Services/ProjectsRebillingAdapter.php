<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Modules\ExpenseManagement\Contracts\ProjectRebillingInterface;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\Projects\Contracts\ProjectExpenseServiceInterface;

/**
 * Records a billable expense line as an out-of-pocket cost of its project, so it can be re-invoiced to the customer.
 */
final class ProjectsRebillingAdapter implements ProjectRebillingInterface
{
    public function __construct(private readonly ProjectExpenseServiceInterface $projects) {}

    public function recharge(ExpenseLine $line, string $userId): ?string
    {
        if ($line->project_id === null || ! $line->billable_to_customer) {
            return null;
        }

        $entry = $this->projects->recordExpense([
            'project_id' => $line->project_id,
            'expense_category' => 'expense',
            'description' => trim($line->merchant_name.' '.($line->description ?? '')),
            'amount' => $line->amount_in_base_currency,
            'incurred_date' => $line->expense_date->toDateString(),
            'receipt_document_id' => $line->receipt_document_id,
            'is_billable' => true,
            'userId' => $userId,
        ]);

        return (string) $entry->id;
    }
}
