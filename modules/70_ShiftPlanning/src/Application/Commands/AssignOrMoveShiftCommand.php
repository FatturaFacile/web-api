<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Commands;

use Carbon\CarbonImmutable;

/**
 * Creates a shift from a template, assigns an open shift or drags an existing shift to another employee or day. A null
 * employee leaves (or makes) the shift open.
 */
final readonly class AssignOrMoveShiftCommand
{
    public function __construct(
        public string $tenantId,
        public string $userId,
        public ?string $assignmentId,
        public ?string $periodId,
        public CarbonImmutable $shiftDate,
        public string $shiftTemplateId,
        public ?string $employeeId,
        public ?string $requiredRoleOrSkillId,
    ) {}
}
