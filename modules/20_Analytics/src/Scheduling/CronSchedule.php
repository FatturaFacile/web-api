<?php

declare(strict_types=1);

namespace Modules\Analytics\Scheduling;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Cron expressions of the report schedules: validation, and the next run computed on the wall clock of the schedule's time
 * zone (so `0 7 * * 1` is 07:00 in Zurich in winter and in summer) and returned in UTC for storage and comparison.
 */
final class CronSchedule
{
    /**
     * Shortest allowed distance between two runs, in minutes.
     */
    public const int MinimumIntervalMinutes = 5;

    /**
     * @throws InvalidArgumentException with the validation message key when the expression or the time zone is not usable
     */
    public function assertValid(string $expression, string $timezone): void
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('analytics_errors.schedule.timezone_invalid');
        }

        if (preg_match('/^[\d\*\/,\-\?A-Za-z ]{5,100}$/', $expression) !== 1 || ! CronExpression::isValidExpression($expression)) {
            throw new InvalidArgumentException('analytics_errors.schedule.cron_invalid');
        }

        $first = $this->nextRunAfter($expression, $timezone, CarbonImmutable::now('UTC'));
        $second = $this->nextRunAfter($expression, $timezone, $first);

        if ($first->diffInMinutes($second, true) < self::MinimumIntervalMinutes) {
            throw new InvalidArgumentException('analytics_errors.schedule.cron_too_frequent');
        }
    }

    /**
     * The first run strictly after the given instant, in UTC.
     */
    public function nextRunAfter(string $expression, string $timezone, CarbonImmutable $after): CarbonImmutable
    {
        try {
            $next = (new CronExpression($expression))->getNextRunDate($after->setTimezone($timezone)->toDateTimeImmutable(), 0, false, $timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('analytics_errors.schedule.cron_invalid', previous: $exception);
        }

        return CarbonImmutable::instance($next)->setTimezone('UTC');
    }

    /**
     * Human readable preview of the next runs, as ISO instants in the time zone of the schedule.
     *
     * @return list<string>
     */
    public function preview(string $expression, string $timezone, int $count = 5): array
    {
        $runs = [];
        $cursor = CarbonImmutable::now('UTC');

        for ($index = 0; $index < $count; $index++) {
            $cursor = $this->nextRunAfter($expression, $timezone, $cursor);
            $runs[] = $cursor->setTimezone($timezone)->toIso8601String();
        }

        return $runs;
    }
}
