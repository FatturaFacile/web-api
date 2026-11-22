<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Services;

use InvalidArgumentException;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Pure step-down (cascade) apportionment. Steps run in ascending step_order. The pool of a step is the net balance of its
 * sending center (its own direct cost plus what earlier steps passed to it); once a step has run, the sender is closed
 * and can never receive again, so cost only flows downstream. Receivers are weighted by the step driver and the pool is
 * split with the Largest Remainder method on cents, so every step adds up to its pool exactly. Nothing is persisted.
 *
 * Step shape: step_order, source_center_id, driver_type, include_descendants, fixed_weights (center id => percentage,
 * FIXED_PERCENTAGE only), target_filter (optional center_ids / categories), driver_values (center id => value, every
 * other driver type).
 */
final class StepDownApportionmentEngine
{
    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, int>  $directCents  direct cost of the period by center, in cents
     * @param  array<string, array{parent_id: string|null, is_active: bool, category: string}>  $centers
     * @return array{steps: list<array<string, mixed>>, allocations: list<array<string, mixed>>, warnings: list<array<string, mixed>>, total_pool_cents: int, total_allocated_cents: int, unallocated_cents: int, final_balances: array<string, int>}
     *
     * @throws InvalidArgumentException when the cascade configuration breaks the step-down rule
     */
    public function run(array $steps, array $directCents, array $centers): array
    {
        usort($steps, static fn (array $a, array $b): int => (int) $a['step_order'] <=> (int) $b['step_order']);
        $this->assertOrdering($steps);

        $balances = $directCents;
        $closed = [];
        $stepLog = [];
        $allocations = [];
        $warnings = [];
        $totalPool = 0;
        $totalAllocated = 0;
        $unallocated = 0;

        foreach ($steps as $step) {
            $order = (int) $step['step_order'];
            $source = (string) $step['source_center_id'];
            $driver = (string) $step['driver_type'];
            $group = [$source];
            if (! empty($step['include_descendants'])) {
                array_push($group, ...$this->descendantsOf($centers, $source));
            }
            foreach ($group as $member) {
                if (isset($closed[$member])) {
                    throw new InvalidArgumentException("Step {$order}: center {$member} was already apportioned by an earlier step.");
                }
            }

            $pool = 0;
            foreach ($group as $member) {
                $pool += $balances[$member] ?? 0;
                $balances[$member] = 0;
            }
            $stepWarnings = [];

            $weights = $driver === 'FIXED_PERCENTAGE'
                ? $this->fixedWeights($step, $group, $closed, $order)
                : $this->driverWeights($step, $group, $closed, $centers, $warnings, $order);
            $weights = $this->applyFilter($weights, $step['target_filter'] ?? null, $centers);
            $weights = array_filter($weights, static fn (string $w): bool => bccomp($w, '0', 8) > 0);
            $driverTotal = '0';
            foreach ($weights as $w) {
                $driverTotal = bcadd($driverTotal, $w, 8);
            }

            $status = 'EXECUTED';
            if ($pool === 0) {
                $status = 'SKIPPED';
                $stepWarnings[] = ['code' => 'EMPTY_POOL', 'step_order' => $order, 'source_center_id' => $source, 'message' => 'The sending center has no cost to apportion.'];
            } elseif (bccomp($driverTotal, '0', 8) === 0) {
                $status = 'SKIPPED';
                $unallocated += $pool;
                $stepWarnings[] = ['code' => 'ZERO_DRIVER_TOTAL', 'step_order' => $order, 'source_center_id' => $source, 'message' => 'The driver total of the receivers is zero: the pool stays unallocated.'];
            }

            $allocatedInStep = 0;
            if ($status === 'EXECUTED') {
                $shares = Money::allocate($pool, $weights);
                foreach ($weights as $target => $weight) {
                    $cents = $shares[$target];
                    $naive = $this->naiveShare($pool, $weight, $driverTotal);
                    $allocations[] = [
                        'step_order' => $order,
                        'source_center_id' => $source,
                        'target_center_id' => (string) $target,
                        'driver_type' => $driver,
                        'driver_value' => $weight,
                        'driver_total' => $driverTotal,
                        'share_ratio' => bcdiv($weight, $driverTotal, 8),
                        'allocated_cents' => $cents,
                        'rounding_adjustment_cents' => $cents - $naive,
                    ];
                    $balances[(string) $target] = ($balances[(string) $target] ?? 0) + $cents;
                    $allocatedInStep += $cents;
                }
                if ($allocatedInStep !== $pool) {
                    throw new InvalidArgumentException("Step {$order}: the allocation drifted from the pool.");
                }
            }

            foreach ($group as $member) {
                $closed[$member] = true;
            }
            $totalPool += $pool;
            $totalAllocated += $allocatedInStep;
            array_push($warnings, ...$stepWarnings);
            $stepLog[] = [
                'step_order' => $order,
                'source_center_id' => $source,
                'driver_type' => $driver,
                'status' => $status,
                'pool_cents' => $pool,
                'allocated_cents' => $allocatedInStep,
                'driver_total' => $driverTotal,
                'warnings' => $stepWarnings,
            ];
        }

        return [
            'steps' => $stepLog,
            'allocations' => $allocations,
            'warnings' => $warnings,
            'total_pool_cents' => $totalPool,
            'total_allocated_cents' => $totalAllocated,
            'unallocated_cents' => $unallocated,
            'final_balances' => $balances,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function assertOrdering(array $steps): void
    {
        $orders = [];
        $sources = [];
        foreach ($steps as $step) {
            $order = (int) $step['step_order'];
            if (isset($orders[$order])) {
                throw new InvalidArgumentException("Duplicate step order {$order}.");
            }
            $orders[$order] = true;
            $source = (string) $step['source_center_id'];
            if (isset($sources[$source])) {
                throw new InvalidArgumentException("Center {$source} is a sender in more than one step: the cascade would flow back.");
            }
            $sources[$source] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  list<string>  $group
     * @param  array<string, bool>  $closed
     * @return array<string, string>
     */
    private function fixedWeights(array $step, array $group, array $closed, int $order): array
    {
        $weights = [];
        $sum = '0';
        foreach ((array) ($step['fixed_weights'] ?? []) as $center => $pct) {
            $center = (string) $center;
            $pct = bcadd((string) $pct, '0', 4);
            if (in_array($center, $group, true) || isset($closed[$center])) {
                throw new InvalidArgumentException("Step {$order}: a fixed percentage targets center {$center}, which is the sender or already closed.");
            }
            $weights[$center] = $pct;
            $sum = bcadd($sum, $pct, 4);
        }
        if (bccomp($sum, '100', 4) !== 0) {
            throw new InvalidArgumentException("Step {$order}: the fixed percentages must add up to 100.0000, got {$sum}.");
        }

        return $weights;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  list<string>  $group
     * @param  array<string, bool>  $closed
     * @param  array<string, array{parent_id: string|null, is_active: bool, category: string}>  $centers
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, string>
     */
    private function driverWeights(array $step, array $group, array $closed, array $centers, array &$warnings, int $order): array
    {
        $weights = [];
        foreach ((array) ($step['driver_values'] ?? []) as $center => $value) {
            $center = (string) $center;
            $value = bcadd((string) $value, '0', 4);
            if (bccomp($value, '0', 4) <= 0) {
                continue;
            }
            if (in_array($center, $group, true) || isset($closed[$center])) {
                $warnings[] = ['code' => 'CLOSED_RECEIVER_EXCLUDED', 'step_order' => $order, 'source_center_id' => (string) $step['source_center_id'], 'message' => "Center {$center} is the sender or already closed and receives nothing."];

                continue;
            }
            if (isset($centers[$center]) && ! $centers[$center]['is_active']) {
                continue;
            }
            $weights[$center] = $value;
        }

        return $weights;
    }

    /**
     * @param  array<string, string>  $weights
     * @param  array<string, array{parent_id: string|null, is_active: bool, category: string}>  $centers
     * @return array<string, string>
     */
    private function applyFilter(array $weights, mixed $filter, array $centers): array
    {
        if (! is_array($filter) || $filter === []) {
            return $weights;
        }
        $ids = $filter['center_ids'] ?? null;
        $categories = $filter['categories'] ?? null;

        return array_filter($weights, static function (string $w, string|int $center) use ($ids, $categories, $centers): bool {
            $center = (string) $center;
            if (is_array($ids) && $ids !== [] && ! in_array($center, $ids, true)) {
                return false;
            }

            return ! (is_array($categories) && $categories !== [] && ! in_array($centers[$center]['category'] ?? '', $categories, true));
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function naiveShare(int $pool, string $weight, string $total): int
    {
        $exact = bcdiv(bcmul((string) $pool, $weight, 8), $total, 8);
        $half = bccomp($exact, '0', 8) < 0 ? '-0.5' : '0.5';

        return (int) bcadd($exact, $half, 0);
    }

    /**
     * @param  array<string, array{parent_id: string|null}>  $centers
     * @return list<string>
     */
    private function descendantsOf(array $centers, string $id): array
    {
        return (new HierarchyValidatorService)->descendantIds($centers, $id);
    }
}
