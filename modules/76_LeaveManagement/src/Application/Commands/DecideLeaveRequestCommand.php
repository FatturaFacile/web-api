<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

final readonly class DecideLeaveRequestCommand
{
    public function __construct(
        public string $tenantId,
        public string $leaveRequestId,
        public string $decision, // approve | reject | cancel
        public ?string $reason = null,
        public ?string $actorUserId = null,
    ) {}
}
