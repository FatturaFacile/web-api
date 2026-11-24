<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\ProductionEngineering;

use Carbon\CarbonImmutable;
use Modules\Manufacturing\Enums\OperationType;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgWorkingCalendar;
use Modules\Manufacturing\Support\Money;

/**
 * Deterministic lead time and standard cost of a routing for a lot.
 *
 * Effective run time of a step = run time per unit x quantity / (parallel units x efficiency / 100). Setup is a lot
 * time and is not scaled. Queue, post-operation wait and move are clock-time waits; setup and run consume working time
 * of the work centre calendar when a start is given.
 *
 * Overlap: when a step has a transfer batch below the lot, the next step starts once the first batch has been queued,
 * set up, run, cooled and moved; the next step still cannot finish before the last batch of the lot has reached it and
 * been processed there. Without overlap steps run one after the other.
 */
final class RoutingLeadTimeCostCalculator
{
    public function __construct(private readonly WorkingCalendarResolver $calendars) {}

    /**
     * @return array<string, mixed>
     */
    public function calculate(MfgRouting $routing, string $quantity = '1', bool $includeOverlap = true, ?CarbonImmutable $start = null): array
    {
        if (! Money::isPositive($quantity)) {
            throw new ManufacturingOperationException('The quantity must be positive.', 'quantity');
        }

        $routing->loadMissing(['operations.workCenter.workingCalendar', 'operations.machineCenter', 'operations.bomComponents.bomComponent.componentItem']);
        /** @var list<MfgOperation> $operations */
        $operations = $routing->operations->all();
        if ($operations === []) {
            throw new ManufacturingOperationException('The routing has no operations.', 'routing_id');
        }

        $qty = (float) $quantity;
        $steps = array_map(fn (MfgOperation $operation): array => $this->step($operation, $quantity, $qty), $operations);

        $timeline = $this->timeline($steps, $qty, $includeOverlap);
        $serial = array_sum(array_column($steps, 'duration_minutes'));
        $calendar = $start !== null ? $this->project($steps, $operations, $qty, $includeOverlap, $start) : null;

        $costs = $this->costs($steps, $quantity);

        $rows = [];
        foreach ($steps as $index => $step) {
            $rows[] = [
                'operation_id' => $step['operation_id'],
                'operation_seq' => $step['sequence'],
                'operation_code' => $step['operation_code'],
                'operation_type' => $step['operation_type'],
                'work_center_id' => $step['work_center_id'],
                'cost_center_id' => $step['cost_center_id'],
                'queue_minutes' => $this->minutes($step['queue']),
                'setup_minutes' => $this->minutes($step['setup']),
                'run_minutes' => $this->minutes($step['run']),
                'post_operation_wait_minutes' => $this->minutes($step['post']),
                'move_minutes' => $this->minutes($step['move']),
                'duration_minutes' => $this->minutes($step['duration_minutes']),
                'start_offset_minutes' => $this->minutes($timeline['starts'][$index]),
                'end_offset_minutes' => $this->minutes($timeline['ends'][$index]),
                'overlap_applied' => $timeline['overlapped'][$index],
                'cost' => $costs['steps'][$index],
                'start_at' => $calendar['steps'][$index]['start_at'] ?? null,
                'end_at' => $calendar['steps'][$index]['end_at'] ?? null,
            ];
        }

        return [
            'routing_id' => (string) $routing->id,
            'quantity' => Money::round($quantity, 4),
            'include_overlap' => $includeOverlap,
            'lead_time' => [
                'total_minutes' => $this->minutes($timeline['total']),
                'serial_minutes' => $this->minutes($serial),
                'overlap_saving_minutes' => $this->minutes($serial - $timeline['total']),
                'calendar' => $calendar === null ? null : [
                    'start_at' => $calendar['start_at'],
                    'finish_at' => $calendar['finish_at'],
                    'elapsed_minutes' => $calendar['elapsed_minutes'],
                ],
            ],
            'steps' => $rows,
            'cost' => [
                'total' => $costs['total'],
                'unit' => Money::round(Money::div($costs['total'], $quantity), 4),
                'setup' => $costs['setup'],
                'machine_run' => $costs['machine_run'],
                'direct_labor' => $costs['direct_labor'],
                'overhead' => $costs['overhead'],
                'subcontracting' => $costs['subcontracting'],
                'material' => $costs['material'],
                'overhead_basis' => 'setup minutes + effective run minutes',
                'by_cost_center' => $costs['by_cost_center'],
            ],
        ];
    }

    /**
     * Times and rates of one operation for the lot.
     *
     * @return array<string, mixed>
     */
    private function step(MfgOperation $operation, string $quantity, float $qty): array
    {
        $workCenter = $operation->workCenter;
        $machine = $operation->machine_center_id !== null ? $operation->machineCenter : null;
        $type = OperationType::tryFrom($operation->operation_type) ?? OperationType::InternalMachining;
        $subcontracted = $type->isSubcontracted() || $operation->is_subcontracted;

        $parallel = max(1, (int) ($machine !== null ? $machine->parallel_capacity_units : ($workCenter->parallel_capacity_units)));
        $efficiency = $machine !== null && $machine->efficiency_factor_percent !== null ? (float) $machine->efficiency_factor_percent : (float) $workCenter->efficiency_rate;
        $efficiency = $efficiency > 0 ? $efficiency : 100.0;
        $divisor = $parallel * ($efficiency / 100);

        $run = ((float) $operation->run_time_minutes_per_unit * $qty) / $divisor;
        $laborPerUnit = (float) $operation->labor_time_minutes_per_unit > 0 ? (float) $operation->labor_time_minutes_per_unit : (float) $operation->run_time_minutes_per_unit;
        $labor = ($laborPerUnit * $qty) / $divisor;
        $queue = (float) $operation->queue_time_minutes > 0 ? (float) $operation->queue_time_minutes : (float) ($workCenter->default_queue_wait_minutes);
        $move = (float) $operation->move_time_minutes > 0 ? (float) $operation->move_time_minutes : (float) ($workCenter->default_move_time_minutes);
        $setup = (float) $operation->setup_time_minutes;
        $post = (float) $operation->post_operation_wait_minutes;

        $overlap = (float) ($operation->overlap_transfer_batch_qty ?? 0);

        return [
            'operation_id' => (string) $operation->id,
            'sequence' => $operation->sequence,
            'operation_code' => $operation->operation_code,
            'operation_type' => $type->value,
            'work_center_id' => (string) $operation->work_center_id,
            'cost_center_id' => $workCenter->cost_center_id,
            'calendar' => $workCenter->workingCalendar,
            'subcontracted' => $subcontracted,
            'machine_costed' => $type === OperationType::InternalMachining && ! $subcontracted,
            'queue' => $queue,
            'setup' => $setup,
            'run' => $run,
            'labor' => $labor,
            'post' => $post,
            'move' => $move,
            'duration_minutes' => $queue + $setup + $run + $post + $move,
            'overlap_batch' => $overlap > 0 && $overlap < $qty ? $overlap : null,
            'operators' => max(1, $operation->required_operators),
            'setup_rate' => $workCenter->hourly_setup_cost_rate,
            'machine_rate' => $machine !== null && $machine->hourly_machine_run_cost_rate_override !== null ? $machine->hourly_machine_run_cost_rate_override : $workCenter->hourly_machine_run_cost_rate,
            'labor_rate' => $workCenter->hourly_cost_rate,
            'overhead_rate' => $workCenter->hourly_overhead_rate,
            'subcontract_unit_cost' => (string) $operation->subcontract_unit_cost,
            'materials' => $operation->bomComponents,
            'quantity' => $quantity,
        ];
    }

    /**
     * Start and end offsets of every step from the start of the first one.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array{starts: list<float>, ends: list<float>, overlapped: list<bool>, total: float}
     */
    private function timeline(array $steps, float $qty, bool $includeOverlap): array
    {
        $starts = [];
        $ends = [];
        $overlapped = [];
        $start = 0.0;
        $floor = 0.0;

        foreach ($steps as $index => $step) {
            $end = max($start + $step['duration_minutes'], $floor);
            $starts[$index] = $start;
            $ends[$index] = $end;
            $batch = $includeOverlap ? $step['overlap_batch'] : null;
            $overlapped[$index] = $batch !== null && isset($steps[$index + 1]);

            if ($batch !== null && isset($steps[$index + 1])) {
                $next = $steps[$index + 1];
                $start += $step['queue'] + $step['setup'] + ($step['run'] * $batch / $qty) + $step['post'] + $step['move'];
                $floor = $end + ($next['run'] * $batch / $qty) + $next['post'] + $next['move'];
            } else {
                $start = $end;
                $floor = 0.0;
            }
        }

        return ['starts' => $starts, 'ends' => $ends, 'overlapped' => $overlapped, 'total' => max($ends)];
    }

    /**
     * Projects the steps on the work centre calendars from a start moment.
     *
     * @param  list<array<string, mixed>>  $steps
     * @param  list<MfgOperation>  $operations
     * @return array{start_at: string, finish_at: string, elapsed_minutes: string, steps: list<array{start_at: string, end_at: string}>}
     */
    private function project(array $steps, array $operations, float $qty, bool $includeOverlap, CarbonImmutable $start): array
    {
        $rows = [];
        $cursor = $start;
        $floor = null;
        $finish = $start;

        foreach ($steps as $index => $step) {
            /** @var MfgWorkingCalendar|null $calendar */
            $calendar = $step['calendar'];
            $queued = $cursor->addSeconds((int) round($step['queue'] * 60));
            $setupDone = $this->work($calendar, $queued, $step['setup']);
            $runDone = $this->work($calendar, $setupDone, $step['run']);
            $end = $runDone->addSeconds((int) round(($step['post'] + $step['move']) * 60));
            if ($floor !== null && $end < $floor) {
                $end = $floor;
            }

            $rows[$index] = ['start_at' => $cursor->toIso8601String(), 'end_at' => $end->toIso8601String()];
            $finish = max($finish, $end);

            $batch = $includeOverlap ? $step['overlap_batch'] : null;
            $next = $steps[$index + 1] ?? null;
            if ($batch !== null && $next !== null) {
                $batchDone = $this->work($calendar, $setupDone, $step['run'] * $batch / $qty);
                $cursor = $batchDone->addSeconds((int) round(($step['post'] + $step['move']) * 60));
                /** @var MfgWorkingCalendar|null $nextCalendar */
                $nextCalendar = $next['calendar'];
                $floor = $this->work($nextCalendar, $end, $next['run'] * $batch / $qty)->addSeconds((int) round(($next['post'] + $next['move']) * 60));
            } else {
                $cursor = $end;
                $floor = null;
            }
        }

        return [
            'start_at' => $start->toIso8601String(),
            'finish_at' => $finish->toIso8601String(),
            'elapsed_minutes' => $this->minutes($start->diffInSeconds($finish, true) / 60),
            'steps' => array_values($rows),
        ];
    }

    private function work(?MfgWorkingCalendar $calendar, CarbonImmutable $from, float $minutes): CarbonImmutable
    {
        return $calendar === null ? $from->addSeconds((int) round($minutes * 60)) : $this->calendars->advance($calendar, $from, $minutes);
    }

    /**
     * Standard cost of every step and the totals, by cost centre.
     *
     * @param  list<array<string, mixed>>  $steps
     * @return array<string, mixed>
     */
    private function costs(array $steps, string $quantity): array
    {
        $totals = ['setup' => '0', 'machine_run' => '0', 'direct_labor' => '0', 'overhead' => '0', 'subcontracting' => '0', 'material' => '0'];
        $rows = [];
        $byCenter = [];

        foreach ($steps as $index => $step) {
            if ($step['subcontracted']) {
                $cost = ['setup' => '0', 'machine_run' => '0', 'direct_labor' => '0', 'overhead' => '0', 'subcontracting' => Money::mul($step['subcontract_unit_cost'], $quantity)];
            } else {
                $basis = $step['setup'] + $step['run'];
                $cost = [
                    'setup' => Money::mul(Money::div($this->dec($step['setup']), '60'), $step['setup_rate']),
                    'machine_run' => $step['machine_costed'] ? Money::mul(Money::div($this->dec($step['run']), '60'), $step['machine_rate']) : '0',
                    'direct_labor' => Money::mul(Money::mul(Money::div($this->dec($step['labor']), '60'), $step['labor_rate']), (string) $step['operators']),
                    'overhead' => Money::mul(Money::div($this->dec($basis), '60'), $step['overhead_rate']),
                    'subcontracting' => '0',
                ];
            }

            $material = '0';
            foreach ($step['materials'] as $link) {
                $component = $link->bomComponent;
                if ($component === null) {
                    continue;
                }
                $scrap = Money::add('1', Money::div((string) $component->scrap_percentage, '100'));
                $perUnit = Money::mul(Money::mul($component->quantity_per, $scrap), (string) ($component->componentItem->standard_cost ?? '0'));
                $material = Money::add($material, Money::mul($perUnit, $quantity));
            }
            $cost['material'] = $material;
            $cost['total'] = array_reduce(array_values($cost), static fn (string $sum, string $value): string => Money::add($sum, $value), '0');

            foreach (['setup', 'machine_run', 'direct_labor', 'overhead', 'subcontracting', 'material'] as $key) {
                $totals[$key] = Money::add($totals[$key], $cost[$key]);
            }

            $center = $step['cost_center_id'] ?? 'unassigned';
            $byCenter[$center] = Money::add($byCenter[$center] ?? '0', $cost['total']);
            $rows[$index] = array_map(static fn (string $value): string => Money::round($value, 4), $cost);
        }

        $total = array_reduce($totals, static fn (string $sum, string $value): string => Money::add($sum, $value), '0');
        $groups = [];
        foreach ($byCenter as $center => $amount) {
            $groups[] = ['cost_center_id' => $center === 'unassigned' ? null : $center, 'total' => Money::round($amount, 4)];
        }

        return array_merge(
            array_map(static fn (string $value): string => Money::round($value, 4), $totals),
            ['total' => Money::round($total, 4), 'steps' => array_values($rows), 'by_cost_center' => $groups],
        );
    }

    private function dec(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    private function minutes(float $value): string
    {
        return number_format(round($value, 3), 3, '.', '');
    }
}
