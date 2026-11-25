<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Events;

/**
 * The reimbursement goes through payroll (Mod 076 / 077): an exempt item and a taxable item (non-traceable payments and
 * per-diem excess subject to IRPEF and contributions).
 */
final readonly class ExpensePayrollItemsReadyEvent
{
    /**
     * @param  list<array{kind: string, amount: string, description: string}>  $items
     */
    public function __construct(
        public string $reportId,
        public string $reportNumber,
        public ?string $employeeId,
        public string $userId,
        public string $currency,
        public array $items,
        public string $tenantId,
    ) {}
}
