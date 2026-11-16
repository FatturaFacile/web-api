<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs\Payroll;

/**
 * Changes to a draft or calculated run. Null properties are left unchanged.
 */
final readonly class UpdatePayrollRunDTO
{
    /**
     * @param  list<string>|null  $employeeIds
     * @param  list<array{employee_id: string, wage_type_code: string, amount: string, label?: string|null}>|null  $inputs
     */
    public function __construct(
        public ?string $paymentDate = null,
        public ?array $employeeIds = null,
        public ?array $inputs = null,
        public ?string $notes = null,
    ) {}
}
