<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Support;

/**
 * An employee as talent and learning sees it: no pay, no personal identifiers.
 */
final readonly class DirectoryEmployee
{
    public function __construct(
        public string $id,
        public string $fullName,
        public ?string $employeeNumber,
        public ?string $positionId,
        public ?string $positionTitle,
        public ?string $departmentId,
        public ?string $departmentName,
        public bool $isActive,
    ) {}

    /**
     * @return array{id: string, full_name: string, employee_number: string|null, position_id: string|null, position_title: string|null, department_id: string|null, department_name: string|null, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->fullName,
            'employee_number' => $this->employeeNumber,
            'position_id' => $this->positionId,
            'position_title' => $this->positionTitle,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'is_active' => $this->isActive,
        ];
    }
}
