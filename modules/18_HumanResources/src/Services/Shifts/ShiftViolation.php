<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Shifts;

/**
 * A finding of the working time rules on a planned day. A blocking one is outside what the law allows and stops the
 * publication unless it is acknowledged with a reason; the others (night or Sunday work needing a permit, a reduced rest
 * allowed once a week) are shown to the planner.
 */
final readonly class ShiftViolation
{
    public function __construct(
        public string $employeeId,
        public string $employeeName,
        public string $date,
        public string $message,
        public bool $isBlocking,
    ) {}

    /**
     * @return array{employee_id: string, employee_name: string, date: string, message: string, is_blocking: bool}
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'employee_name' => $this->employeeName,
            'date' => $this->date,
            'message' => $this->message,
            'is_blocking' => $this->isBlocking,
        ];
    }
}
