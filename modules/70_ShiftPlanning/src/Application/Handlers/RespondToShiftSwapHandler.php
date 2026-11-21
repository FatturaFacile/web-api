<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\RespondToShiftSwapCommand;
use Modules\ShiftPlanning\Application\DTOs\ComplianceReport;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Events\ShiftSwapStatusChangedEvent;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftConflictException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftSwapRequest;

/**
 * The two decisions of a swap. A colleague accepts only if the shift fits the colleague's own plan; the manager approves only
 * if both employees stay compliant after the exchange, checked again inside the transaction on the locked shifts.
 */
final class RespondToShiftSwapHandler
{
    public function __construct(
        private readonly ShiftComplianceValidator $validator,
        private readonly NotificationPort $notifications,
        private readonly TimeAndPayrollIntegrationPort $payroll,
    ) {}

    /**
     * @throws ShiftAccessDeniedException
     * @throws ShiftComplianceException
     * @throws ShiftConflictException
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(RespondToShiftSwapCommand $command): ShiftSwapRequest
    {
        $swap = $command->asManager ? $this->managerDecision($command) : $this->peerDecision($command);

        event(new ShiftSwapStatusChangedEvent($command->tenantId, $swap->id, $swap->status, $swap->requester_employee_id, $swap->target_peer_employee_id));

        return $swap;
    }

    private function peerDecision(RespondToShiftSwapCommand $command): ShiftSwapRequest
    {
        $peerId = $command->actorEmployeeId ?? throw new ShiftAccessDeniedException('Only an employee can answer a swap request.');

        $swap = DB::transaction(function () use ($command, $peerId): ShiftSwapRequest {
            $swap = $this->lockedSwap($command->tenantId, $command->swapRequestId);

            $this->assertOpenToPeer($swap, $peerId);

            if ($command->approve) {
                $this->assertTaker($swap, $peerId, $this->shifts($swap), true);
                $swap->acceptByPeer($peerId);
            } elseif (! $swap->isBroadcast()) {
                // A colleague declining a broadcast leaves the offer open for the others.
                $swap->rejectByPeer();
            }

            return $swap;
        });

        $key = match ($swap->status) {
            SwapStatus::Rejected => 'SHIFT_PLANNING.NOTIFY_SWAP_DECLINED',
            SwapStatus::PendingManagerApproval => 'SHIFT_PLANNING.NOTIFY_SWAP_ACCEPTED_BY_PEER',
            default => null,
        };

        if ($key !== null) {
            $this->notifications->notifyEmployee($command->tenantId, $swap->requester_employee_id, $key, ['swap_id' => $swap->id]);
        }

        return $swap;
    }

    private function managerDecision(RespondToShiftSwapCommand $command): ShiftSwapRequest
    {
        $userId = $command->actorUserId ?? throw new ShiftAccessDeniedException('Only a manager can decide a swap request.');

        if (! $command->approve && ($command->rejectionReason === null || trim($command->rejectionReason) === '')) {
            throw new ShiftPlanningException('A reason is required to reject a swap request.');
        }

        /** @var array{0: ShiftSwapRequest, 1: list<ScheduledShiftAssignment>} $outcome */
        $outcome = DB::transaction(function () use ($command, $userId): array {
            $swap = $this->lockedSwap($command->tenantId, $command->swapRequestId);

            if (! $command->approve) {
                $swap->rejectByManager($userId, (string) $command->rejectionReason);

                return [$swap, []];
            }

            return [$swap, $this->exchange($swap, $userId)];
        });

        [$swap, $changed] = $outcome;

        foreach ($changed as $assignment) {
            $this->payroll->assignmentFinalized($assignment);
        }

        foreach ([$swap->requester_employee_id, $swap->target_peer_employee_id] as $employeeId) {
            if ($employeeId !== null) {
                $this->notifications->notifyEmployee($command->tenantId, $employeeId, $command->approve ? 'SHIFT_PLANNING.NOTIFY_SWAP_APPROVED' : 'SHIFT_PLANNING.NOTIFY_SWAP_REJECTED', ['swap_id' => $swap->id]);
            }
        }

        return $swap;
    }

    /**
     * Gives the offered shift to the colleague and, in a one-to-one swap, the colleague's shift to the requester.
     *
     * @return list<ScheduledShiftAssignment>
     */
    private function exchange(ShiftSwapRequest $swap, string $userId): array
    {
        $shifts = $this->shifts($swap);
        $peerId = (string) $swap->target_peer_employee_id;
        $offered = $shifts['offered'];
        $counter = $shifts['counter'];

        if ($offered->employee_id !== $swap->requester_employee_id || ($counter !== null && $counter->employee_id !== $peerId)) {
            throw new ShiftConflictException('A shift of the swap changed hands after the request.');
        }

        $reports = [
            $offered->id => $this->assertTaker($swap, $peerId, $shifts, false),
        ];

        if ($counter !== null) {
            $reports[$counter->id] = $this->assertTaker($swap, $swap->requester_employee_id, $shifts, false);
        }

        $changed = [];

        foreach ([[$offered, $peerId], [$counter, $swap->requester_employee_id]] as [$assignment, $newEmployeeId]) {
            if ($assignment instanceof ScheduledShiftAssignment) {
                $report = $reports[$assignment->id];
                $assignment->update([
                    'employee_id' => $newEmployeeId,
                    'estimated_labor_cost' => $report->estimatedLaborCost,
                    'surcharge_breakdown' => $report->surcharges->toArray(),
                    'compliance_warnings' => $report->issuesAsArray(),
                ]);
                $changed[] = $assignment;
            }
        }

        $swap->approve($userId);

        return $changed;
    }

    /**
     * Checks that the employee can take over the shift of the swap that is not the employee's own, with both swapped shifts
     * left out of the plan the employee is checked against.
     *
     * @param  array{offered: ScheduledShiftAssignment, counter: ScheduledShiftAssignment|null}  $shifts
     *
     * @throws ShiftComplianceException
     */
    private function assertTaker(ShiftSwapRequest $swap, string $takerId, array $shifts, bool $asPeerAcceptance): ComplianceReport
    {
        if ($asPeerAcceptance && $swap->status->value !== 'pending_peer_acceptance') {
            throw new ShiftConflictException('The swap is no longer waiting for a colleague.');
        }

        $incoming = $takerId === $swap->requester_employee_id ? $shifts['counter'] : $shifts['offered'];

        if (! $incoming instanceof ScheduledShiftAssignment) {
            return new ComplianceReport([], SurchargeBreakdown::none(), '0.00');
        }

        if ($incoming->period->status !== RosterStatus::Published) {
            throw new ShiftPlanningException('Shifts can be swapped only on a published roster.');
        }

        $excluded = array_values(array_filter([$shifts['offered']->id, $shifts['counter']?->id]));
        $report = $this->validator->validate((string) $swap->tenant_id, $incoming->period->jurisdiction, $incoming->toPlannedShift($takerId), $incoming->required_role_or_skill_id, $excluded);

        if ($report->hasBlocking()) {
            throw new ShiftComplianceException($report->issues);
        }

        return $report;
    }

    private function assertOpenToPeer(ShiftSwapRequest $swap, string $peerId): void
    {
        if ($swap->status->value !== 'pending_peer_acceptance') {
            throw new ShiftConflictException('The swap is no longer waiting for a colleague.');
        }

        if ($peerId === $swap->requester_employee_id || (! $swap->isBroadcast() && $swap->target_peer_employee_id !== $peerId)) {
            throw new ShiftAccessDeniedException('This swap was not offered to you.');
        }
    }

    /**
     * @return array{offered: ScheduledShiftAssignment, counter: ScheduledShiftAssignment|null}
     */
    private function shifts(ShiftSwapRequest $swap): array
    {
        $load = static fn (string $id): ScheduledShiftAssignment => ScheduledShiftAssignment::query()->with(['period', 'template'])
            ->where('tenant_id', $swap->tenant_id)->whereKey($id)->lockForUpdate()->first()
            ?? throw new ShiftNotFoundException('A shift of the swap no longer exists.');

        return [
            'offered' => $load($swap->shift_assignment_id),
            'counter' => $swap->target_shift_assignment_id === null ? null : $load($swap->target_shift_assignment_id),
        ];
    }

    private function lockedSwap(string $tenantId, string $swapId): ShiftSwapRequest
    {
        return ShiftSwapRequest::query()->where('tenant_id', $tenantId)->whereKey($swapId)->lockForUpdate()->first()
            ?? throw new ShiftNotFoundException('Unknown swap request.');
    }
}
