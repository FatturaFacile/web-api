<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\CopyOrGenerateRosterTemplateCommand;
use Modules\ShiftPlanning\Application\Services\ShiftAssignmentWriter;
use Modules\ShiftPlanning\Domain\Enums\PeriodType;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Domain\Services\RotationPlanner;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * Generates a draft period from a rotation pattern or by cloning a previous period. Generated shifts are stored together
 * with their compliance findings, blocking ones included: the planner fixes them in the matrix, and publishing is refused
 * until they are gone.
 */
final class CopyOrGenerateRosterTemplateHandler
{
    public function __construct(
        private readonly ShiftAssignmentWriter $writer,
        private readonly RotationPlanner $planner,
    ) {}

    /**
     * @return array{period: RosterSchedulePeriod, created: int, open: int, blocking: int, warnings: int}
     *
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(CopyOrGenerateRosterTemplateCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $source = $command->sourcePeriodId === null ? null : RosterSchedulePeriod::query()
                ->where('tenant_id', $command->tenantId)->whereKey($command->sourcePeriodId)->first()
                ?? throw new ShiftNotFoundException('Unknown source roster period.');

            if ($source === null && $command->pattern === null) {
                throw new ShiftPlanningException('Choose a rotation pattern or a period to copy.');
            }

            $period = $this->createPeriod($command, $source);
            $slots = $source === null ? $this->patternSlots($command, $period) : $this->cloneSlots($source, $period);

            return $this->store($command, $period, $slots);
        });
    }

    private function createPeriod(CopyOrGenerateRosterTemplateCommand $command, ?RosterSchedulePeriod $source): RosterSchedulePeriod
    {
        $start = $command->startDate->startOfDay();
        $end = $command->periodType === PeriodType::Weekly ? $start->addDays(6) : $start->endOfMonth()->startOfDay();

        $overlapping = RosterSchedulePeriod::query()
            ->where('tenant_id', $command->tenantId)
            ->where('location_or_department_id', $command->locationOrDepartmentId)
            ->where('production_line_id', $command->productionLineId)
            ->whereDate('start_date', '<=', $end->format('Y-m-d'))
            ->whereDate('end_date', '>=', $start->format('Y-m-d'))
            ->exists();

        if ($overlapping) {
            throw new ShiftPlanningException('A roster period already exists for these dates and this location.');
        }

        $period = new RosterSchedulePeriod([
            'tenant_id' => $command->tenantId,
            'location_or_department_id' => $command->locationOrDepartmentId ?? $source?->location_or_department_id,
            'production_line_id' => $command->productionLineId ?? $source?->production_line_id,
            'period_type' => $command->periodType,
            'jurisdiction' => $command->jurisdiction,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => RosterStatus::Draft,
        ]);
        $period->assertIntegrity();
        $period->save();

        return $period;
    }

    /**
     * @return list<array{date: CarbonImmutable, template: ShiftTemplate, employee_id: string|null, skill: string|null}>
     */
    private function patternSlots(CopyOrGenerateRosterTemplateCommand $command, RosterSchedulePeriod $period): array
    {
        $templates = ShiftTemplate::query()->where('tenant_id', $command->tenantId)->where('is_active', true)->whereIn('id', $command->shiftTemplateIds)->get()->keyBy('id');
        $ordered = array_values(array_filter(array_map(static fn (string $id): ?ShiftTemplate => $templates->get($id), $command->shiftTemplateIds)));

        if ($ordered === [] || $command->employeeIds === []) {
            throw new ShiftPlanningException('A rotation pattern needs at least one employee and one shift template.');
        }

        $slots = [];

        foreach ($this->planner->plan($command->pattern, $period->start_date, $period->end_date, count($command->employeeIds), count($ordered)) as $slot) {
            $slots[] = ['date' => $slot['date'], 'template' => $ordered[$slot['template_index']], 'employee_id' => $command->employeeIds[$slot['employee_index']], 'skill' => null];
        }

        foreach ($command->openSlotsPerTemplate as $templateId => $count) {
            $template = $templates->get($templateId);

            if ($template === null) {
                continue;
            }

            for ($day = $period->start_date; $day <= $period->end_date; $day = $day->addDay()) {
                for ($i = 0; $i < $count; $i++) {
                    $slots[] = ['date' => $day, 'template' => $template, 'employee_id' => null, 'skill' => null];
                }
            }
        }

        return $slots;
    }

    /**
     * @return list<array{date: CarbonImmutable, template: ShiftTemplate, employee_id: string|null, skill: string|null}>
     */
    private function cloneSlots(RosterSchedulePeriod $source, RosterSchedulePeriod $period): array
    {
        $offset = (int) $source->start_date->diffInDays($period->start_date, false);
        $slots = [];

        foreach ($source->assignments()->with('template')->orderBy('shift_date')->get() as $assignment) {
            $date = $assignment->shift_date->addDays($offset);

            if ($assignment->template !== null && $period->containsDate($date)) {
                $slots[] = ['date' => $date, 'template' => $assignment->template, 'employee_id' => $assignment->employee_id, 'skill' => $assignment->required_role_or_skill_id];
            }
        }

        return $slots;
    }

    /**
     * @param  list<array{date: CarbonImmutable, template: ShiftTemplate, employee_id: string|null, skill: string|null}>  $slots
     * @return array{period: RosterSchedulePeriod, created: int, open: int, blocking: int, warnings: int}
     */
    private function store(CopyOrGenerateRosterTemplateCommand $command, RosterSchedulePeriod $period, array $slots): array
    {
        usort($slots, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);
        $open = $blocking = $warnings = 0;

        foreach ($slots as $slot) {
            $result = $this->writer->write($period, $slot['template'], $slot['date'], $slot['employee_id'], $slot['skill'], null, false);
            $open += $slot['employee_id'] === null ? 1 : 0;
            $blocking += $result->report !== null && $result->report->hasBlocking() ? 1 : 0;
            $warnings += $result->report !== null ? count($result->report->warnings()) : 0;
        }

        return ['period' => $period, 'created' => count($slots), 'open' => $open, 'blocking' => $blocking, 'warnings' => $warnings];
    }
}
