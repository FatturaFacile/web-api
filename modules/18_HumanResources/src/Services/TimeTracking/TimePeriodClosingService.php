<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Exceptions\HumanResourcesException;
use Modules\HumanResources\Exceptions\TimePeriodClosedException;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimePeriodClosing;

/**
 * Monthly closing of the working time records. A closed month refuses every change to its clock events and daily
 * records (in local Swiss/Italian time, the time zone of the records); reopening needs a reason, which stays in the
 * time audit history with the closing itself.
 */
final class TimePeriodClosingService
{
    /**
     * Refuse the change when the local day falls in a closed month of the active company.
     */
    public function assertOpen(CarbonInterface $instant): void
    {
        $local = SwissWorkingTimeRules::toLocal(CarbonImmutable::instance($instant));

        if ($this->isClosed($local->year, $local->month)) {
            throw TimePeriodClosedException::forMonth($local->year, $local->month);
        }
    }

    /**
     * Refuse the change when a calendar day (Y-m-d) falls in a closed month.
     */
    public function assertDayOpen(string $day): void
    {
        $date = CarbonImmutable::parse($day);

        if ($this->isClosed($date->year, $date->month)) {
            throw TimePeriodClosedException::forMonth($date->year, $date->month);
        }
    }

    /**
     * Whether the month is closed in the active company.
     */
    public function isClosed(int $year, int $month): bool
    {
        return TimePeriodClosing::query()->where('year', $year)->where('month', $month)->where('status', TimePeriodClosing::StatusClosed)->exists();
    }

    /**
     * Close a month: the totals of the records at closing time are kept with the closing.
     */
    public function close(User $actor, int $year, int $month, bool $transferToPayroll = false): TimePeriodClosing
    {
        return DB::transaction(function () use ($actor, $year, $month, $transferToPayroll): TimePeriodClosing {
            $closing = TimePeriodClosing::query()->where('year', $year)->where('month', $month)->lockForUpdate()->first();

            if ($closing?->isClosed()) {
                throw new TimePeriodClosedException(sprintf('Il mese %02d/%d è già chiuso.', $month, $year));
            }

            $from = sprintf('%04d-%02d-01', $year, $month);
            $to = CarbonImmutable::parse($from)->endOfMonth()->format('Y-m-d');
            $summary = [
                'clock_events' => TimeClockEvent::query()->betweenLocalDays($from, $to)->count(),
                'daily_records' => TimeDailyRecord::query()->whereBetween('work_date', [$from, $to])->count(),
                'employees' => TimeClockEvent::query()->betweenLocalDays($from, $to)->distinct()->count('user_id'),
            ];

            if ($transferToPayroll) {
                $summary['payroll_transfer'] = app(TimeToPayrollTransferService::class)->transfer($year, $month, $actor);
            }

            $closing ??= new TimePeriodClosing(['year' => $year, 'month' => $month]);
            $closing->fill(['status' => TimePeriodClosing::StatusClosed, 'closed_at' => now(), 'closed_by' => $actor->id, 'summary' => $summary])->save();
            $this->audit($actor, $closing, 'Closed', null);

            return $closing;
        });
    }

    /**
     * Reopen a closed month with a reason.
     */
    public function reopen(User $actor, TimePeriodClosing $closing, string $reason): TimePeriodClosing
    {
        return DB::transaction(function () use ($actor, $closing, $reason): TimePeriodClosing {
            $locked = TimePeriodClosing::query()->whereKey($closing->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isClosed()) {
                throw new HumanResourcesException('Il mese non è chiuso.');
            }

            $locked->fill([
                'status' => TimePeriodClosing::StatusReopened,
                'reopened_at' => now(),
                'reopened_by' => $actor->id,
                'reopen_reason' => $reason,
                'reopen_count' => $locked->reopen_count + 1,
            ])->save();
            $this->audit($actor, $locked, 'Reopened', $reason);

            return $locked;
        });
    }

    private function audit(User $actor, TimePeriodClosing $closing, string $action, ?string $reason): void
    {
        TimeAuditEntry::create([
            'user_id' => null,
            'entity_type' => 'TimePeriodClosing',
            'entity_id' => $closing->id,
            'action' => $action,
            'before_values' => null,
            'after_values' => ['year' => $closing->year, 'month' => $closing->month, 'status' => $closing->status],
            'reason' => $reason,
            'actor_id' => $actor->id,
            'actor_name' => (string) $actor->name,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
