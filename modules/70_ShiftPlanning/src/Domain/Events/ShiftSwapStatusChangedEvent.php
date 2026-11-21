<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Events;

use Modules\ShiftPlanning\Domain\Enums\SwapStatus;

final readonly class ShiftSwapStatusChangedEvent
{
    public function __construct(
        public string $tenantId,
        public string $swapRequestId,
        public SwapStatus $status,
        public string $requesterEmployeeId,
        public ?string $peerEmployeeId,
    ) {}
}
