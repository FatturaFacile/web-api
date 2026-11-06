<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Modules\Associations\Enums\ScheduleOccurrence;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseSchedule;
use Modules\Associations\Models\CourseSuspension;

/**
 * Works out the lessons of a course from its schedules and its suspensions.
 */
final class CourseCalendar
{
    /**
     * @return list<array{course_id: string, title: string, date: string, starts_at: string, ends_at: string, resource_id: ?string, suspended: bool}>
     */
    public function occurrences(Course $course, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $first = $from->max($course->starts_on);
        $last = $course->ends_on === null ? $to : $to->min($course->ends_on);

        if ($first->greaterThan($last)) {
            return [];
        }

        $schedules = $course->schedules()->get();
        $suspensions = $course->suspensions()->get();
        $occurrences = [];

        foreach (CarbonPeriod::create($first, $last) as $day) {
            $date = CarbonImmutable::parse($day);

            foreach ($schedules as $schedule) {
                if ($this->falls($schedule, $date)) {
                    $occurrences[] = [
                        'course_id' => $course->id,
                        'title' => (string) $course->title,
                        'date' => $date->toDateString(),
                        'starts_at' => substr((string) $schedule->starts_at, 0, 5),
                        'ends_at' => substr((string) $schedule->ends_at, 0, 5),
                        'resource_id' => $schedule->resource_id ?? $course->resource_id,
                        'suspended' => $suspensions->contains(static fn (CourseSuspension $suspension): bool => $date->betweenIncluded($suspension->starts_on, $suspension->ends_on)),
                    ];
                }
            }
        }

        usort($occurrences, static fn (array $left, array $right): int => [$left['date'], $left['starts_at']] <=> [$right['date'], $right['starts_at']]);

        return $occurrences;
    }

    /**
     * @return 'lesson'|'suspended'|'none'
     */
    public function dayState(Course $course, CarbonImmutable $day): string
    {
        $occurrences = $this->occurrences($course, $day, $day);

        if ($occurrences === []) {
            return 'none';
        }

        return collect($occurrences)->every(static fn (array $occurrence): bool => $occurrence['suspended']) ? 'suspended' : 'lesson';
    }

    private function falls(CourseSchedule $schedule, CarbonImmutable $day): bool
    {
        return match ($schedule->pattern) {
            ScheduleOccurrence::Daily => true,
            ScheduleOccurrence::Weekday => $day->dayOfWeekIso === $schedule->weekday,
            ScheduleOccurrence::Date => $schedule->on_date?->isSameDay($day) ?? false,
        };
    }
}
