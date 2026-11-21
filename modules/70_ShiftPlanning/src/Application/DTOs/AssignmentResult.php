<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\DTOs;

use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

final readonly class AssignmentResult
{
    public function __construct(
        public ScheduledShiftAssignment $assignment,
        public ?ComplianceReport $report,
        public ?string $previousEmployeeId,
    ) {}
}
