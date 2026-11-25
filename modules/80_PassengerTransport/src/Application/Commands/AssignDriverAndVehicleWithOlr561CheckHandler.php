<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Application\Commands;

use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\FleetVehicle;
use Modules\PassengerTransport\Models\PassengerBooking;
use Modules\PassengerTransport\Services\DriverAssignmentService;

/**
 * Validates the driver and the vehicle for the trip (documents on the trip date, overlaps, OLR 1 / OLR 2 / EC 561 simulation) and assigns them.
 * With dry run it only reports the findings.
 */
final class AssignDriverAndVehicleWithOlr561CheckHandler
{
    public function __construct(private readonly DriverAssignmentService $assignment) {}

    /**
     * @return array{regime: string, issues: list<array<string, mixed>>, blocking_count: int, warning_count: int, can_assign: bool, remaining_minutes: int, next_mandatory_break_or_rest_at: string|null}
     */
    public function handle(AssignDriverAndVehicleWithOlr561CheckCommand $command): array
    {
        $booking = PassengerBooking::query()->findOrFail($command->bookingId);
        $vehicle = FleetVehicle::query()->findOrFail($command->vehicleId);
        $state = DriverComplianceState::query()->where('driver_employee_id', $command->driverEmployeeId)->first()
            ?? throw new PassengerTransportException('driver_without_compliance_record', 422);

        return $command->dryRun
            ? $this->assignment->check($booking, $vehicle, $state, $command->plannedBreakMinutes)
            : $this->assignment->assign($booking, $vehicle, $state, $command->plannedBreakMinutes, $command->acknowledgeWarnings, $command->userId);
    }
}
