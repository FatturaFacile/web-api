<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Services;

use Illuminate\Support\Carbon;
use Modules\SkiOutdoorSchool\Domain\Enums\AvailabilityKind;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * What occupies the instructors on one day: blackouts, explicit availability windows, private lessons and group course sessions. Loads the
 * whole day for a set of instructors with three queries and answers the "is this slot free" question for the planning board, the fair
 * rotation and the conflict detection of every booking.
 *
 * @phpstan-type DayLoad array{blackouts: list<TimeRange>, windows: list<TimeRange>, lessons: list<array{id: string, range: TimeRange}>, courses: list<array{id: string, range: TimeRange}>}
 */
final class InstructorScheduleService
{
    /**
     * @param  list<string>  $instructorIds
     * @return array<string, DayLoad>
     */
    public function dayLoads(string $date, array $instructorIds): array
    {
        $loads = [];

        foreach ($instructorIds as $id) {
            $loads[$id] = ['blackouts' => [], 'windows' => [], 'lessons' => [], 'courses' => []];
        }

        if ($instructorIds === []) {
            return $loads;
        }

        $availability = InstructorAvailability::query()->whereIn('instructor_id', $instructorIds)->whereDate('slot_date', $date)->get();

        foreach ($availability as $row) {
            $range = TimeRange::fromTimes((string) $row->start_time, (string) $row->end_time);
            $row->kind === AvailabilityKind::BLACKOUT ? $loads[(string) $row->instructor_id]['blackouts'][] = $range : $loads[(string) $row->instructor_id]['windows'][] = $range;
        }

        $lessons = SkiLesson::query()
            ->whereIn('instructor_id', $instructorIds)
            ->whereDate('lesson_date', $date)
            ->whereIn('status', array_map(static fn (LessonStatus $status): string => $status->value, array_filter(LessonStatus::cases(), static fn (LessonStatus $status): bool => $status->occupiesInstructor())))
            ->get(['id', 'instructor_id', 'start_time', 'end_time']);

        foreach ($lessons as $lesson) {
            $loads[(string) $lesson->instructor_id]['lessons'][] = ['id' => (string) $lesson->id, 'range' => TimeRange::fromTimes((string) $lesson->start_time, (string) $lesson->end_time)];
        }

        $courses = GroupCourse::query()
            ->whereIn('instructor_id', $instructorIds)
            ->whereDate('week_start', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->where('status', '!=', CourseStatus::CANCELLED->value)
            ->get(['id', 'instructor_id', 'daily_start_time', 'duration_minutes']);

        foreach ($courses as $course) {
            $loads[(string) $course->instructor_id]['courses'][] = ['id' => (string) $course->id, 'range' => TimeRange::fromStart((string) $course->daily_start_time, (int) $course->duration_minutes)];
        }

        return $loads;
    }

    /**
     * Why the range is not free in the day load of an instructor, or null when it is.
     *
     * @param  DayLoad  $load
     * @return 'blackout'|'outside_availability'|'lesson_conflict'|'course_conflict'|null
     */
    public function conflict(array $load, TimeRange $range, ?string $ignoreLessonId = null): ?string
    {
        foreach ($load['blackouts'] as $blackout) {
            if ($blackout->overlaps($range)) {
                return 'blackout';
            }
        }

        if ($load['windows'] !== [] && ! array_filter($load['windows'], static fn (TimeRange $window): bool => $window->contains($range))) {
            return 'outside_availability';
        }

        foreach ($load['lessons'] as $lesson) {
            if ($lesson['id'] !== $ignoreLessonId && $lesson['range']->overlaps($range)) {
                return 'lesson_conflict';
            }
        }

        foreach ($load['courses'] as $course) {
            if ($course['range']->overlaps($range)) {
                return 'course_conflict';
            }
        }

        return null;
    }

    public function conflictFor(string $instructorId, string $date, TimeRange $range, ?string $ignoreLessonId = null): ?string
    {
        $load = $this->dayLoads($date, [$instructorId])[$instructorId];

        return $this->conflict($load, $range, $ignoreLessonId);
    }

    /**
     * Hours already assigned to each instructor between two dates (private lessons and the days of the group courses), and the last time
     * a lesson was assigned to him or her: the load that the fair rotation balances.
     *
     * @param  list<string>  $instructorIds
     * @return array<string, array{hours: float, last_assigned_at: string|null}>
     */
    public function load(array $instructorIds, string $from, string $to): array
    {
        $result = [];

        foreach ($instructorIds as $id) {
            $result[$id] = ['hours' => 0.0, 'last_assigned_at' => null];
        }

        if ($instructorIds === []) {
            return $result;
        }

        $lessons = SkiLesson::query()
            ->whereIn('instructor_id', $instructorIds)
            ->whereDate('lesson_date', '>=', $from)
            ->whereDate('lesson_date', '<=', $to)
            ->whereIn('status', [LessonStatus::REQUESTED->value, LessonStatus::CONFIRMED->value, LessonStatus::COMPLETED->value])
            ->get(['instructor_id', 'duration_minutes', 'updated_at']);

        foreach ($lessons as $lesson) {
            $id = (string) $lesson->instructor_id;
            $result[$id]['hours'] += $lesson->duration_minutes / 60;
            $stamp = $lesson->updated_at?->toDateTimeString();

            if ($stamp !== null && ($result[$id]['last_assigned_at'] === null || $stamp > $result[$id]['last_assigned_at'])) {
                $result[$id]['last_assigned_at'] = $stamp;
            }
        }

        $courses = GroupCourse::query()
            ->whereIn('instructor_id', $instructorIds)
            ->whereDate('week_start', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->where('status', '!=', CourseStatus::CANCELLED->value)
            ->get(['instructor_id', 'week_start', 'end_date', 'duration_minutes']);

        foreach ($courses as $course) {
            $start = max($course->week_start->toDateString(), $from);
            $end = min($course->end_date->toDateString(), $to);
            $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;
            $result[(string) $course->instructor_id]['hours'] += $days * $course->duration_minutes / 60;
        }

        return array_map(static fn (array $row): array => ['hours' => round($row['hours'], 2), 'last_assigned_at' => $row['last_assigned_at']], $result);
    }

    /**
     * First and last day of the rotation period around a date.
     *
     * @return array{0: string, 1: string}
     */
    public function period(string $date, string $period = 'week'): array
    {
        $day = Carbon::parse($date);

        return $period === 'month'
            ? [$day->copy()->startOfMonth()->toDateString(), $day->copy()->endOfMonth()->toDateString()]
            : [$day->copy()->startOfWeek()->toDateString(), $day->copy()->endOfWeek()->toDateString()];
    }
}
