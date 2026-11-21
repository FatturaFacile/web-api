<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Commands;

/**
 * A decision on a swap. A colleague answers as $actorEmployeeId (first step); a manager answers as $actorUserId (second
 * step) and must give a reason when rejecting.
 */
final readonly class RespondToShiftSwapCommand
{
    public function __construct(
        public string $tenantId,
        public string $swapRequestId,
        public bool $asManager,
        public bool $approve,
        public ?string $actorEmployeeId = null,
        public ?string $actorUserId = null,
        public ?string $rejectionReason = null,
    ) {}
}
