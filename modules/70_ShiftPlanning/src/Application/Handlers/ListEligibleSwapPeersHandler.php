<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\ShiftPlanning\Application\Queries\ListEligibleSwapPeersQuery;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftAccessDeniedException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * Candidates for a peer swap: colleagues with the skill of the shift and a contract on its day who pass every blocking check
 * for it. The colleague's own shifts of the next two months are listed so the wizard can offer a one-to-one exchange.
 */
final class ListEligibleSwapPeersHandler
{
    private const int CANDIDATE_LIMIT = 50;

    private const int SHIFTS_PER_PEER = 10;

    private const int SHIFT_HORIZON_DAYS = 60;

    public function __construct(
        private readonly ShiftComplianceValidator $validator,
        private readonly EmployeeContractPort $contracts,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ShiftAccessDeniedException
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(ListEligibleSwapPeersQuery $query): array
    {
        $assignment = ScheduledShiftAssignment::query()->with(['template', 'period'])
            ->where('tenant_id', $query->tenantId)->whereKey($query->assignmentId)->first()
            ?? throw new ShiftNotFoundException('Unknown shift assignment.');

        if ($assignment->employee_id !== $query->employeeId) {
            throw new ShiftAccessDeniedException('Only the assigned employee can look for a colleague to swap with.');
        }

        if ($assignment->period->status !== RosterStatus::Published) {
            throw new ShiftPlanningException('Shifts can be swapped only on a published roster.');
        }

        $day = CarbonImmutable::parse($assignment->shift_date->format('Y-m-d'));
        $candidates = array_slice(array_values(array_diff($this->contracts->employeesWithSkill($assignment->required_role_or_skill_id, $day), [$query->employeeId])), 0, self::CANDIDATE_LIMIT);
        $profiles = $this->contracts->profilesFor($candidates, $day);
        $peers = [];

        foreach ($candidates as $candidateId) {
            $profile = $profiles[$candidateId] ?? null;

            if ($profile === null) {
                continue;
            }

            $report = $this->validator->validate($query->tenantId, $assignment->period->jurisdiction, $assignment->toPlannedShift($candidateId), $assignment->required_role_or_skill_id, [$assignment->id]);

            if ($report->hasBlocking()) {
                continue;
            }

            $peers[] = [
                'employee_id' => $candidateId,
                'name' => $profile->fullName,
                'warnings' => array_map(static fn ($issue): array => $issue->toArray(), $report->warnings()),
                'shifts' => $this->upcomingShifts($query->tenantId, $candidateId),
            ];
        }

        usort($peers, static fn (array $a, array $b): int => [count($a['warnings']), $a['name']] <=> [count($b['warnings']), $b['name']]);

        return $peers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function upcomingShifts(string $tenantId, string $employeeId): array
    {
        return ScheduledShiftAssignment::query()->with('template')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employeeId)
            ->where('start_at', '>', CarbonImmutable::now())
            ->where('start_at', '<', CarbonImmutable::now()->addDays(self::SHIFT_HORIZON_DAYS))
            ->whereHas('period', static fn (Builder $period) => $period->where('status', RosterStatus::Published))
            ->orderBy('start_at')
            ->limit(self::SHIFTS_PER_PEER)
            ->get()
            ->map(static fn (ScheduledShiftAssignment $shift): array => [
                'id' => $shift->id,
                'shift_date' => $shift->shift_date->format('Y-m-d'),
                'template_code' => $shift->template?->code,
                'start_at' => $shift->start_at->toIso8601String(),
                'end_at' => $shift->end_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
