<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Modules\ShiftPlanning\Application\Queries\ListShiftSwapRequestsQuery;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftSwapRequest;
use Modules\ShiftPlanning\Presentation\Http\Resources\ShiftSwapRequestResource;

/**
 * Lists swap requests. For the manager inbox every request waiting for approval carries the compliance impact: what each
 * shift looked like with its current owner and what it would look like in the new hands, side by side.
 */
final class ListShiftSwapRequestsHandler
{
    public function __construct(private readonly ShiftComplianceValidator $validator) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(ListShiftSwapRequestsQuery $query): array
    {
        $swaps = ShiftSwapRequest::query()
            ->with(['assignment.template', 'assignment.period', 'targetAssignment.template', 'targetAssignment.period'])
            ->where('tenant_id', $query->tenantId)
            ->when($query->status !== null, static fn (Builder $q) => $q->where('status', $query->status))
            ->when($query->employeeId !== null, static fn (Builder $q) => $q->where(static function (Builder $inner) use ($query): void {
                $inner->where('requester_employee_id', $query->employeeId)
                    ->orWhere('target_peer_employee_id', $query->employeeId)
                    ->orWhere(static fn (Builder $broadcast) => $broadcast->whereNull('target_peer_employee_id')->where('status', SwapStatus::PendingPeerAcceptance));
            }))
            ->latest()
            ->limit(200)
            ->get();

        return $swaps->map(function (ShiftSwapRequest $swap) use ($query): array {
            $row = ShiftSwapRequestResource::make($swap)->resolve();

            if ($query->employeeId !== null) {
                // An employee sees the shifts of the swap, never what they cost the company.
                unset($row['assignment']['estimated_labor_cost'], $row['target_assignment']['estimated_labor_cost']);
            }

            if ($query->withImpact && $swap->status === SwapStatus::PendingManagerApproval) {
                $row['compliance_impact'] = $this->impact($swap);
            }

            return $row;
        })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function impact(ShiftSwapRequest $swap): array
    {
        $offered = $swap->assignment;
        $counter = $swap->targetAssignment;
        $excluded = array_values(array_filter([$offered->id, $counter?->id]));

        $peerSide = $this->side($swap, $offered, (string) $swap->target_peer_employee_id, $excluded);
        $requesterSide = $counter === null ? null : $this->side($swap, $counter, $swap->requester_employee_id, $excluded);

        return [
            'peer_takes_offered_shift' => $peerSide,
            'requester_takes_counter_shift' => $requesterSide,
            'can_approve' => ! $peerSide['has_blocking'] && ($requesterSide === null || ! $requesterSide['has_blocking']),
        ];
    }

    /**
     * @param  list<string>  $excluded
     * @return array<string, mixed>
     */
    private function side(ShiftSwapRequest $swap, ScheduledShiftAssignment $shift, string $newEmployeeId, array $excluded): array
    {
        $report = $this->validator->validate((string) $swap->tenant_id, $shift->period->jurisdiction, $shift->toPlannedShift($newEmployeeId), $shift->required_role_or_skill_id, $excluded);

        return [
            'assignment_id' => $shift->id,
            'current_employee_id' => $shift->employee_id,
            'new_employee_id' => $newEmployeeId,
            'current' => ['compliance_warnings' => $shift->compliance_warnings ?? [], 'estimated_labor_cost' => $shift->estimated_labor_cost],
            'after' => ['compliance_warnings' => $report->issuesAsArray(), 'estimated_labor_cost' => $report->estimatedLaborCost],
            'has_blocking' => $report->hasBlocking(),
        ];
    }
}
