<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

final readonly class CreatePayrollRunDTO
{
    /**
     * @param  list<string>|null  $employeeIds  restrict the run to these employees (null = every employee with a payroll profile)
     * @param  list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>  $inputs
     */
    public function __construct(
        public string $tenantId,
        public ?string $companyId,
        public int $periodYear,
        public int $periodMonth,
        public ?string $paymentDate = null,
        public ?array $employeeIds = null,
        public array $inputs = [],
        public ?string $notes = null,
    ) {}
}
