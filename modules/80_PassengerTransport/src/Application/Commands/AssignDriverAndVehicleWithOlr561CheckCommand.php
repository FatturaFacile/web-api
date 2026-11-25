<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

final readonly class AssignDriverAndVehicleWithOlr561CheckCommand
{
    public function __construct(
        public string $bookingId,
        public string $vehicleId,
        public string $driverEmployeeId,
        public int $plannedBreakMinutes,
        public bool $acknowledgeWarnings,
        public bool $dryRun,
        public ?string $userId,
    ) {}
}
