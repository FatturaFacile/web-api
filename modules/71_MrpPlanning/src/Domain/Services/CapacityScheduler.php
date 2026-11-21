<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\MrpPlanning\Domain\ValueObjects\RoutingOperation;
use Modules\MrpPlanning\Domain\ValueObjects\SchedulableOrder;
use Modules\MrpPlanning\Domain\ValueObjects\ScheduleBlock;
use Modules\MrpPlanning\Domain\ValueObjects\WorkCenterCalendar;

/**
 * Capacity requirements planning on daily buckets. Orders are placed in due-date order (earliest due date first).
 *
 * - Infinite capacity: every operation fills the nominal minutes of its work centre day by day, ignoring what other
 *   orders already load, so stacked orders show up as overloaded buckets.
 * - Finite capacity: an operation only takes the minutes still free. Backward scheduling starts from the due date and
 *   falls back to a forward pass from today when the order would have to start in the past. Alternative work centres
 *   are tried and the one that finishes the operation best (latest start backward, earliest end forward) wins.
 *
 * Fixed blocks (firm orders, manual placements by the planner) stay where they are and count as load first.
 */
final class CapacityScheduler
{
    private const int GuardDays = 1500;

    /**
     * @param  list<SchedulableOrder>  $orders
     * @param  array<string, WorkCenterCalendar>  $calendars
     * @param  array<string, list<string>>  $alternatives
     * @param  list<ScheduleBlock>  $fixedBlocks
     * @return list<ScheduleBlock> the fixed blocks followed by the newly placed ones
     */
    public function schedule(array $orders, array $calendars, array $alternatives, CarbonImmutable $today, bool $finite, bool $backward, array $fixedBlocks = []): array
    {
        $today = $today->startOfDay();
        $loaded = [];
        foreach ($fixedBlocks as $block) {
            $this->load($loaded, $block->workCenterId, $block->segments);
        }

        usort($orders, static fn (SchedulableOrder $a, SchedulableOrder $b): int => $a->needBy <=> $b->needBy);

        $blocks = $fixedBlocks;
        foreach ($orders as $order) {
            $operations = $order->operations;
            usort($operations, static fn (RoutingOperation $a, RoutingOperation $b): int => $a->sequence <=> $b->sequence);

            $placed = null;
            if ($backward) {
                $placed = $this->placeOrder($order, $operations, $calendars, $alternatives, $loaded, $finite, true, $order->needBy);
                if ($finite && $placed !== null && $this->earliest($placed) < $today) {
                    $placed = null;
                }
            }
            $placed ??= $this->placeOrder($order, $operations, $calendars, $alternatives, $loaded, $finite, false, max($today, $order->plannedStart->startOfDay()));

            foreach ($placed ?? [] as $block) {
                $this->load($loaded, $block->workCenterId, $block->segments);
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * Places (or re-places) one operation from a day, e.g. after a manual drag.
     *
     * @param  array<string, WorkCenterCalendar>  $calendars
     * @param  list<ScheduleBlock>  $otherBlocks  every other block, which load the buckets
     * @return list<array{date: string, minutes: int}>
     */
    public function placeSingle(string $workCenterId, int $minutes, CarbonImmutable $anchor, bool $backward, bool $finite, array $calendars, array $otherBlocks): array
    {
        $loaded = [];
        foreach ($otherBlocks as $block) {
            $this->load($loaded, $block->workCenterId, $block->segments);
        }

        return $this->place($calendars[$workCenterId] ?? new WorkCenterCalendar($workCenterId, []), $loaded[$workCenterId] ?? [], $minutes, $anchor, $backward, $finite);
    }

    /**
     * Daily buckets of the blocks: available and loaded minutes per work centre and day.
     *
     * @param  list<ScheduleBlock>  $blocks
     * @param  array<string, WorkCenterCalendar>  $calendars
     * @return list<array{work_center_id: string, bucket_date: string, available_minutes: int, loaded_minutes: int}>
     */
    public function buckets(array $blocks, array $calendars): array
    {
        $loaded = [];
        foreach ($blocks as $block) {
            $this->load($loaded, $block->workCenterId, $block->segments);
        }

        $rows = [];
        foreach ($loaded as $workCenterId => $days) {
            ksort($days);
            foreach ($days as $date => $minutes) {
                $rows[] = [
                    'work_center_id' => (string) $workCenterId,
                    'bucket_date' => (string) $date,
                    'available_minutes' => ($calendars[$workCenterId] ?? null)?->availableMinutes(CarbonImmutable::parse((string) $date)) ?? 0,
                    'loaded_minutes' => $minutes,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<RoutingOperation>  $operations
     * @param  array<string, WorkCenterCalendar>  $calendars
     * @param  array<string, list<string>>  $alternatives
     * @param  array<string, array<string, int>>  $loaded
     * @return list<ScheduleBlock>|null
     */
    private function placeOrder(SchedulableOrder $order, array $operations, array $calendars, array $alternatives, array $loaded, bool $finite, bool $backward, CarbonImmutable $anchor): ?array
    {
        $sequence = $backward ? array_reverse($operations) : $operations;
        $cursor = $anchor->startOfDay();
        $blocks = [];

        foreach ($sequence as $operation) {
            $minutes = $operation->minutesFor($order->quantity);
            if ($minutes <= 0) {
                continue;
            }

            $candidates = [$operation->workCenterId, ...($finite ? ($alternatives[$operation->workCenterId] ?? []) : [])];
            $best = null;
            foreach ($candidates as $workCenterId) {
                $calendar = $calendars[$workCenterId] ?? new WorkCenterCalendar($workCenterId, []);
                $segments = $this->place($calendar, $loaded[$workCenterId] ?? [], $minutes, $cursor, $backward, $finite);
                $start = CarbonImmutable::parse($segments[0]['date']);
                $end = CarbonImmutable::parse($segments[array_key_last($segments)]['date']);
                if ($best === null || ($backward ? $start > $best['start'] : $end < $best['end'])) {
                    $best = ['workCenterId' => $workCenterId, 'segments' => $segments, 'start' => $start, 'end' => $end];
                }
            }

            /** @var array{workCenterId: string, segments: list<array{date: string, minutes: int}>, start: CarbonImmutable, end: CarbonImmutable} $best */
            $this->load($loaded, $best['workCenterId'], $best['segments']);
            $blocks[] = new ScheduleBlock(
                id: (string) Str::uuid(),
                proposalId: $order->proposalId,
                workCenterId: $best['workCenterId'],
                sequence: $operation->sequence,
                startDate: $best['start'],
                endDate: $best['end'],
                minutes: $minutes,
                segments: $best['segments'],
                originalWorkCenterId: $best['workCenterId'] !== $operation->workCenterId ? $operation->workCenterId : null,
            );
            $cursor = $backward ? $best['start'] : $best['end'];
        }

        return $blocks === [] ? null : $blocks;
    }

    /**
     * Fills minutes day by day from the anchor; segments come back in chronological order.
     *
     * @param  array<string, int>  $loadedByDate
     * @return list<array{date: string, minutes: int}>
     */
    private function place(WorkCenterCalendar $calendar, array $loadedByDate, int $minutes, CarbonImmutable $anchor, bool $backward, bool $finite): array
    {
        $anchor = $anchor->startOfDay();
        if (! $calendar->hasAnyCapacity()) {
            return [['date' => $anchor->toDateString(), 'minutes' => $minutes]];
        }

        $segments = [];
        $remaining = $minutes;
        $day = $anchor;

        for ($guard = 0; $remaining > 0 && $guard < self::GuardDays; $guard++) {
            $available = $calendar->availableMinutes($day);
            if ($available > 0) {
                $free = $finite ? max(0, $available - ($loadedByDate[$day->toDateString()] ?? 0)) : $available;
                $take = min($remaining, $free);
                if ($take > 0) {
                    $segments[] = ['date' => $day->toDateString(), 'minutes' => $take];
                    $remaining -= $take;
                }
            }
            $day = $backward ? $day->subDay() : $day->addDay();
        }

        if ($remaining > 0) {
            $segments[] = ['date' => $day->toDateString(), 'minutes' => $remaining];
        }

        usort($segments, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return $segments;
    }

    /**
     * @param  array<string, array<string, int>>  $loaded
     * @param  list<array{date: string, minutes: int}>  $segments
     */
    private function load(array &$loaded, string $workCenterId, array $segments): void
    {
        foreach ($segments as $segment) {
            $loaded[$workCenterId][$segment['date']] = ($loaded[$workCenterId][$segment['date']] ?? 0) + $segment['minutes'];
        }
    }

    /**
     * @param  list<ScheduleBlock>  $blocks
     */
    private function earliest(array $blocks): CarbonImmutable
    {
        return min(array_map(static fn (ScheduleBlock $block): CarbonImmutable => $block->startDate, $blocks));
    }
}
