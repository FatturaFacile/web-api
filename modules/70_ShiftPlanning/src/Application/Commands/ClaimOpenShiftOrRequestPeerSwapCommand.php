<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Commands;

use Modules\ShiftPlanning\Domain\Enums\ClaimMode;

/**
 * Employee self-service: claim an open shift ($assignmentId is the open shift) or request a swap ($assignmentId is the own
 * shift, $targetPeerEmployeeId the colleague or null for every qualified colleague, $targetAssignmentId the colleague's
 * shift for a one-to-one exchange).
 */
final readonly class ClaimOpenShiftOrRequestPeerSwapCommand
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public ClaimMode $mode,
        public string $assignmentId,
        public ?string $targetPeerEmployeeId = null,
        public ?string $targetAssignmentId = null,
    ) {}
}
