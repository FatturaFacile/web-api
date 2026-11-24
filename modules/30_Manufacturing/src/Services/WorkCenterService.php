<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Manufacturing\Exceptions\ManufacturingOperationException;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;
use Modules\Manufacturing\Models\MfgWorkCenterShift;
use Modules\Manufacturing\Support\Money;

/**
 * Work centre calendars and capacity. The gross hours of a day are its dated exception, else the weekly calendar of
 * its weekday, else the daily capacity of the work centre when no weekly calendar is set; effective hours apply
 * efficiency and availability. The load of a day is the remaining planned time of the open order operations
 * scheduled on it.
 */
final readonly class WorkCenterService
{
    /** Longest capacity window returned at once. */
    private const int MaxDays = 366;

    /**
     * Replaces the weekly calendar of the work centre.
     *
     * @param  list<array{weekday: int, available_hours: string|int|float}>  $days
     */
    public function setCalendar(MfgWorkCenter $workCenter, array $days): MfgWorkCenter
    {
        return DB::transaction(function () use ($workCenter, $days): MfgWorkCenter {
            MfgWorkCenter::query()->whereKey($workCenter->id)->lockForUpdate()->firstOrFail();
            MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->forceDelete();

            foreach ($days as $day) {
                MfgWorkCenterShift::create([
                    'tenant_id' => $workCenter->tenant_id,
                    'work_center_id' => $workCenter->id,
                    'weekday' => $day['weekday'],
                    'available_hours' => Money::round($day['available_hours']),
                ]);
            }

            return $workCenter->load('shifts');
        });
    }

    /**
     * Sets the gross hours of one day (holiday with 0 hours, extra shift), replacing an earlier exception of that day.
     */
    public function setException(MfgWorkCenter $workCenter, string $date, string $hours, ?string $reason): MfgWorkCenterCapacity
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        /** @var MfgWorkCenterCapacity $exception */
        $exception = MfgWorkCenterCapacity::withTrashed()->updateOrCreate(
            ['tenant_id' => $workCenter->tenant_id, 'work_center_id' => $workCenter->id, 'calendar_date' => $day],
            ['available_hours' => Money::round($hours), 'reason' => $reason, 'deleted_at' => null]
        );

        return $exception;
    }

    /**
     * Gross hours of a day for the work centre.
     *
     * @param  Collection<int, MfgWorkCenterShift>|null  $shifts
     * @param  Collection<string, MfgWorkCenterCapacity>|null  $exceptions
     */
    public function grossHours(MfgWorkCenter $workCenter, CarbonImmutable $day, ?Collection $shifts = null, ?Collection $exceptions = null): string
    {
        $exceptions ??= MfgWorkCenterCapacity::query()->where('work_center_id', $workCenter->id)->whereDate('calendar_date', $day->toDateString())->get()->keyBy(static fn (MfgWorkCenterCapacity $row): string => $row->calendar_date->toDateString());
        $shifts ??= MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->get();

        $exception = $exceptions->get($day->toDateString());
        if ($exception instanceof MfgWorkCenterCapacity) {
            return Money::of($exception->available_hours);
        }
        if ($shifts->isNotEmpty()) {
            $shift = $shifts->first(static fn (MfgWorkCenterShift $row): bool => $row->weekday === $day->dayOfWeekIso);

            return $shift instanceof MfgWorkCenterShift ? Money::of($shift->available_hours) : '0';
        }

        return Money::of($workCenter->capacity_hours_per_day);
    }

    /**
     * Capacity, load and utilization per day of the work centre between two dates.
     *
     * @return list<array{date: string, gross_hours: string, available_hours: string, load_hours: string, utilization_percent: string|null, is_exception: bool}>
     */
    public function capacity(MfgWorkCenter $workCenter, string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) >= self::MaxDays) {
            throw new ManufacturingOperationException('The capacity window must end after it starts and span at most '.self::MaxDays.' days.', 'to');
        }

        $shifts = MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->get();
        $exceptions = MfgWorkCenterCapacity::query()->where('work_center_id', $workCenter->id)
            ->whereDate('calendar_date', '>=', $start->toDateString())->whereDate('calendar_date', '<=', $end->toDateString())->get()
            ->keyBy(static fn (MfgWorkCenterCapacity $row): string => $row->calendar_date->toDateString());

        $loads = MfgProductionOrderOperation::query()
            ->join('mfg_production_orders as o', 'o.id', '=', 'mfg_production_order_operations.production_order_id')
            ->where('mfg_production_order_operations.work_center_id', $workCenter->id)
            ->whereIn('o.status', ['released', 'in_progress'])
            ->whereNull('o.deleted_at')
            ->where('mfg_production_order_operations.status', '!=', 'completed')
            ->whereDate('mfg_production_order_operations.scheduled_date', '>=', $start->toDateString())->whereDate('mfg_production_order_operations.scheduled_date', '<=', $end->toDateString())
            ->get(['mfg_production_order_operations.*'])
            ->groupBy(static fn (MfgProductionOrderOperation $operation): string => (string) $operation->scheduled_date?->toDateString());

        $rows = [];
        foreach (CarbonPeriod::create($start, $end) as $date) {
            $day = CarbonImmutable::parse($date);
            $gross = $this->grossHours($workCenter, $day, $shifts, $exceptions);
            $available = $workCenter->effectiveHours($gross);
            $minutes = ($loads->get($day->toDateString()) ?? collect())->reduce(
                static fn (string $sum, MfgProductionOrderOperation $operation): string => Money::add($sum, self::remainingMinutes($operation)),
                '0'
            );
            $load = Money::div($minutes, '60');

            $rows[] = [
                'date' => $day->toDateString(),
                'gross_hours' => Money::round($gross),
                'available_hours' => Money::round($available),
                'load_hours' => Money::round($load),
                'utilization_percent' => Money::isPositive($available) ? Money::round(Money::mul(Money::div($load, $available), '100')) : null,
                'is_exception' => $exceptions->has($day->toDateString()),
            ];
        }

        return $rows;
    }

    /**
     * Forward-schedules a planned time on the work centre from a day: the first day with capacity, and the day after
     * the time is used up (to start the next operation).
     *
     * @return array{start: CarbonImmutable, next: CarbonImmutable}
     */
    public function schedule(MfgWorkCenter $workCenter, CarbonImmutable $from, string $minutes): array
    {
        $shifts = MfgWorkCenterShift::query()->where('work_center_id', $workCenter->id)->get();
        $exceptions = MfgWorkCenterCapacity::query()->where('work_center_id', $workCenter->id)
            ->whereDate('calendar_date', '>=', $from->toDateString())->whereDate('calendar_date', '<=', $from->addDays(self::MaxDays)->toDateString())->get()
            ->keyBy(static fn (MfgWorkCenterCapacity $row): string => $row->calendar_date->toDateString());

        $day = $from;
        $start = null;
        $remaining = Money::of($minutes);

        for ($i = 0; $i < self::MaxDays; $i++) {
            $capacity = Money::mul($workCenter->effectiveHours($this->grossHours($workCenter, $day, $shifts, $exceptions)), '60');
            if (Money::isPositive($capacity)) {
                $start ??= $day;
                $remaining = Money::sub($remaining, $capacity);
                if (! Money::isPositive($remaining)) {
                    return ['start' => $start, 'next' => $day->addDay()];
                }
            }
            $day = $day->addDay();
        }

        return ['start' => $start ?? $from, 'next' => $day];
    }

    /**
     * Planned minutes of an operation not yet confirmed.
     */
    private static function remainingMinutes(MfgProductionOrderOperation $operation): string
    {
        $planned = Money::add($operation->planned_setup_minutes, $operation->planned_run_minutes);
        $actual = Money::add($operation->actual_setup_minutes, $operation->actual_run_minutes);

        return Money::max('0', Money::sub($planned, $actual));
    }
}
