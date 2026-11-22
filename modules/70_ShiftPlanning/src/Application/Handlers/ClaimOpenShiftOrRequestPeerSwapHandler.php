<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\ClaimOpenShiftOrRequestPeerSwapCommand;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Enums\ClaimMode;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Events\ShiftSwapStatusChangedEvent;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftConflictException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftSwapRequest;

/**
 * An employee claims an open shift (checked against the same rules as a manager assignment, saved at once) or asks a
 * colleague for a swap (the first step of the two-step approval).
 */
final class ClaimOpenShiftOrRequestPeerSwapHandler
{
    /** Colleagues told about a broadcast swap. */
    private const int BROADCAST_LIMIT = 50;

    public function __construct(
        private readonly ShiftComplianceValidator $validator,
        private readonly EmployeeContractPort $contracts,
        private readonly NotificationPort $notifications,
        private readonly TimeAndPayrollIntegrationPort $payroll,
    ) {}

    /**
     * @return array{assignment: ScheduledShiftAssignment|null, swap: ShiftSwapRequest|null, warnings: list<array<string, mixed>>}
     *
     * @throws ShiftAccessDeniedException
     * @throws ShiftComplianceException
     * @throws ShiftConflictException
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(ClaimOpenShiftOrRequestPeerSwapCommand $command): array
    {
        return $command->mode === ClaimMode::Claim ? $this->claim($command) : $this->requestSwap($command);
    }

    /**
     * @return array{assignment: ScheduledShiftAssignment|null, swap: ShiftSwapRequest|null, warnings: list<array<string, mixed>>}
     */
    private function claim(ClaimOpenShiftOrRequestPeerSwapCommand $command): array
    {
        $result = DB::transaction(function () use ($command): array {
            $assignment = $this->lockedAssignment($command->tenantId, $command->assignmentId);
            $period = $assignment->period;

            if ($period->status !== RosterStatus::Published) {
                throw new ShiftPlanningException('Open shifts can be claimed only on a published roster.');
            }

            if (! $assignment->isOpen()) {
                throw new ShiftConflictException('The shift has already been taken.');
            }

            $this->assertFuture($assignment);
            $report = $this->validator->validate($command->tenantId, $period->jurisdiction, $assignment->toPlannedShift($command->employeeId), $assignment->required_role_or_skill_id);

            if ($report->hasBlocking()) {
                throw new ShiftComplianceException($report->issues);
            }

            $assignment->update([
                'employee_id' => $command->employeeId,
                'estimated_labor_cost' => $report->estimatedLaborCost,
                'surcharge_breakdown' => $report->surcharges->toArray(),
                'compliance_warnings' => $report->issuesAsArray(),
            ]);

            return [$assignment, $report->issuesAsArray()];
        });

        [$assignment, $warnings] = $result;
        $this->payroll->assignmentFinalized($assignment);
        $this->notifications->notifyEmployee($command->tenantId, $command->employeeId, 'SHIFT_PLANNING.NOTIFY_SHIFT_CLAIMED', ['date' => $assignment->shift_date->format('Y-m-d')]);

        return ['assignment' => $assignment, 'swap' => null, 'warnings' => $warnings];
    }

    /**
     * @return array{assignment: ScheduledShiftAssignment|null, swap: ShiftSwapRequest|null, warnings: list<array<string, mixed>>}
     */
    private function requestSwap(ClaimOpenShiftOrRequestPeerSwapCommand $command): array
    {
        $swap = DB::transaction(function () use ($command): ShiftSwapRequest {
            $assignment = $this->lockedAssignment($command->tenantId, $command->assignmentId);

            if ($assignment->employee_id !== $command->employeeId) {
                throw new ShiftAccessDeniedException('Only the assigned employee can offer a shift for a swap.');
            }

            if ($assignment->period->status !== RosterStatus::Published) {
                throw new ShiftPlanningException('Shifts can be swapped only on a published roster.');
            }

            $this->assertFuture($assignment);

            if ($command->targetPeerEmployeeId === $command->employeeId) {
                throw new ShiftPlanningException('A shift cannot be swapped with yourself.');
            }

            if ($command->targetAssignmentId !== null) {
                $target = $this->lockedAssignment($command->tenantId, $command->targetAssignmentId);

                if ($command->targetPeerEmployeeId === null || $target->employee_id !== $command->targetPeerEmployeeId) {
                    throw new ShiftPlanningException('The shift to receive must belong to the chosen colleague.');
                }

                $this->assertFuture($target);
            }

            $alreadyOpen = ShiftSwapRequest::query()->where('tenant_id', $command->tenantId)
                ->where('shift_assignment_id', $assignment->id)
                ->whereIn('status', [SwapStatus::PendingPeerAcceptance, SwapStatus::PendingManagerApproval])->exists();

            if ($alreadyOpen) {
                throw new ShiftConflictException('The shift is already offered in an open swap request.');
            }

            return ShiftSwapRequest::create([
                'tenant_id' => $command->tenantId,
                'requester_employee_id' => $command->employeeId,
                'target_peer_employee_id' => $command->targetPeerEmployeeId,
                'shift_assignment_id' => $assignment->id,
                'target_shift_assignment_id' => $command->targetAssignmentId,
                'status' => SwapStatus::PendingPeerAcceptance,
            ]);
        });

        $this->notifyPeers($command, $swap);

        return ['assignment' => null, 'swap' => $swap, 'warnings' => []];
    }

    private function notifyPeers(ClaimOpenShiftOrRequestPeerSwapCommand $command, ShiftSwapRequest $swap): void
    {
        $assignment = $swap->assignment;
        $peers = $swap->target_peer_employee_id !== null
            ? [$swap->target_peer_employee_id]
            : array_slice(array_values(array_diff(
                $this->contracts->employeesWithSkill($assignment->required_role_or_skill_id, CarbonImmutable::parse($assignment->shift_date->format('Y-m-d'))),
                [$command->employeeId],
            )), 0, self::BROADCAST_LIMIT);

        foreach ($peers as $peerId) {
            $this->notifications->notifyEmployee($command->tenantId, $peerId, 'SHIFT_PLANNING.NOTIFY_SWAP_REQUESTED', ['date' => $assignment->shift_date->format('Y-m-d')]);
        }

        event(new ShiftSwapStatusChangedEvent($command->tenantId, $swap->id, $swap->status, $swap->requester_employee_id, $swap->target_peer_employee_id));
    }

    private function lockedAssignment(string $tenantId, string $assignmentId): ScheduledShiftAssignment
    {
        return ScheduledShiftAssignment::query()->with(['period', 'template'])->where('tenant_id', $tenantId)->whereKey($assignmentId)->lockForUpdate()->first()
            ?? throw new ShiftNotFoundException('Unknown shift assignment.');
    }

    private function assertFuture(ScheduledShiftAssignment $assignment): void
    {
        if ($assignment->start_at <= CarbonImmutable::now()) {
            throw new ShiftPlanningException('The shift has already started.');
        }
    }
}
