<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Queries;

use Modules\SkiOutdoorSchool\Application\Services\InstructorScheduleService;
use Modules\SkiOutdoorSchool\Application\Services\RotationCandidateBuilder;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\LessonStatus;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorAvailability;
use Modules\SkiOutdoorSchool\Models\SkiLesson;

/**
 * Read models of the planning board: the high-density multi-instructor grid of one day (30 or 55 minute slots) with the filters of the
 * secretariat, and the fair rotation ranking for a slot.
 *
 * @phpstan-type Filters array<string, mixed>
 */
final class PlanningQueries
{
    public function __construct(private readonly InstructorScheduleService $schedule, private readonly RotationCandidateBuilder $rotation) {}

    /**
     * @param  Filters  $filters  discipline, language, instructor_id (requested instructor), only_available (with slot_start), slot_start, slot_minutes, search
     * @return array<string, mixed>
     */
    public function board(string $date, array $filters): array
    {
        $slotMinutes = (int) ($filters['slot_minutes'] ?? config('ski_school.slot_minutes.0'));
        $slotMinutes = in_array($slotMinutes, (array) config('ski_school.slot_minutes'), true) ? $slotMinutes : (int) config('ski_school.slot_minutes.0');
        $dayStart = TimeRange::toMinutes((string) config('ski_school.board.day_start'));
        $dayEnd = TimeRange::toMinutes((string) config('ski_school.board.day_end'));
        $slots = [];

        for ($minute = $dayStart; $minute + $slotMinutes <= $dayEnd; $minute += $slotMinutes) {
            $slots[] = ['start' => TimeRange::format($minute), 'end' => TimeRange::format($minute + $slotMinutes)];
        }

        $instructors = Instructor::query()->with(['languages', 'disciplines'])->where('is_active', true)
            ->when($filters['instructor_id'] ?? null, static fn ($query, string $id) => $query->where('id', $id))
            ->orderBy('last_name')->orderBy('first_name')->get()
            ->filter(static fn (Instructor $instructor): bool => (! isset($filters['discipline']) || in_array((string) $filters['discipline'], $instructor->disciplineValues(), true))
                && (! isset($filters['language']) || in_array(strtoupper((string) $filters['language']), $instructor->languageCodes(), true))
                && (! isset($filters['search']) || str_contains(mb_strtolower($instructor->fullName()), mb_strtolower((string) $filters['search']))))
            ->values();
        $ids = $instructors->map(static fn (Instructor $instructor): string => (string) $instructor->id)->all();
        $loads = $this->schedule->dayLoads($date, $ids);
        [$from, $to] = $this->schedule->period($date);
        $hours = $this->schedule->load($ids, $from, $to);
        $lessons = SkiLesson::query()->whereDate('lesson_date', $date)->whereIn('status', [LessonStatus::REQUESTED->value, LessonStatus::CONFIRMED->value, LessonStatus::COMPLETED->value])->orderBy('start_time')->get();
        $blackouts = InstructorAvailability::query()->whereIn('instructor_id', $ids)->whereDate('slot_date', $date)->get()->groupBy('instructor_id');
        $courses = GroupCourse::query()->with('level')->whereIn('instructor_id', $ids)->whereDate('week_start', '<=', $date)->whereDate('end_date', '>=', $date)->where('status', '!=', CourseStatus::CANCELLED->value)->get()->groupBy('instructor_id');
        $probe = isset($filters['slot_start']) ? TimeRange::fromStart((string) $filters['slot_start'], $slotMinutes) : null;
        $rows = [];

        foreach ($instructors as $instructor) {
            $id = (string) $instructor->id;
            $busy = $probe !== null ? $this->schedule->conflict($loads[$id], $probe) : null;

            if (($filters['only_available'] ?? false) && $probe !== null && $busy !== null) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'name' => $instructor->fullName(),
                'languages' => $instructor->languageCodes(),
                'disciplines' => $instructor->disciplineValues(),
                'hours_in_week' => $hours[$id]['hours'],
                'max_weekly_hours' => $instructor->max_weekly_hours === null ? null : (float) $instructor->max_weekly_hours,
                'busy_reason' => $busy,
                'free_slots' => array_values(array_map(
                    static fn (array $slot): string => $slot['start'],
                    array_filter($slots, fn (array $slot): bool => $this->schedule->conflict($loads[$id], TimeRange::fromTimes($slot['start'], $slot['end'])) === null),
                )),
                'lessons' => $lessons->where('instructor_id', $id)->map(fn (SkiLesson $lesson): array => $this->lessonRow($lesson))->values()->all(),
                'courses' => ($courses->get($id) ?? collect())->map(static fn (GroupCourse $course): array => [
                    'id' => (string) $course->id, 'code' => $course->code, 'start_time' => $course->daily_start_time,
                    'end_time' => TimeRange::fromStart((string) $course->daily_start_time, $course->duration_minutes)->end(), 'level_id' => (string) $course->level_id, 'discipline' => $course->discipline->value,
                ])->values()->all(),
                'blackouts' => ($blackouts->get($id) ?? collect())->map(static fn (InstructorAvailability $row): array => [
                    'id' => (string) $row->id, 'start_time' => $row->start_time, 'end_time' => $row->end_time, 'kind' => $row->kind->value, 'note' => $row->note,
                ])->values()->all(),
            ];
        }

        $unassigned = $lessons->whereNull('instructor_id')
            ->when($filters['discipline'] ?? null, static fn ($collection, string $discipline) => $collection->filter(static fn (SkiLesson $lesson): bool => $lesson->discipline->value === $discipline))
            ->when($filters['language'] ?? null, static fn ($collection, string $language) => $collection->filter(static fn (SkiLesson $lesson): bool => $lesson->language_code === strtoupper($language)))
            ->map(fn (SkiLesson $lesson): array => $this->lessonRow($lesson))->values()->all();

        return [
            'date' => $date,
            'slot_minutes' => $slotMinutes,
            'day_start' => (string) config('ski_school.board.day_start'),
            'day_end' => (string) config('ski_school.board.day_end'),
            'slots' => $slots,
            'instructors' => $rows,
            'unassigned' => $unassigned,
            'totals' => ['lessons' => $lessons->count(), 'unassigned' => count($unassigned), 'instructors' => count($rows)],
        ];
    }

    /**
     * Fair rotation ranking of all active instructors for one slot: eligible ones first by lowest load, with the reasons of the others.
     *
     * @param  Filters  $params  date, start_time, slot_minutes, slot_count, discipline, language, requested_instructor_id, period, ignore_lesson_id
     * @return list<array{instructor_id: string, name: string, rank: int|null, eligible: bool, is_requested: bool, hours_in_period: float, reasons: list<string>}>
     */
    public function rotation(array $params): array
    {
        $range = TimeRange::fromStart((string) $params['start_time'], (int) $params['slot_minutes'] * (int) ($params['slot_count'] ?? 1));
        $period = ($params['period'] ?? 'week') === 'month' ? 'month' : 'week';

        return $this->rotation->ranking(
            (string) $params['date'], $range, (string) $params['discipline'], (string) $params['language'],
            isset($params['requested_instructor_id']) && $params['requested_instructor_id'] !== '' ? (string) $params['requested_instructor_id'] : null,
            $period, isset($params['ignore_lesson_id']) ? (string) $params['ignore_lesson_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonRow(SkiLesson $lesson): array
    {
        return [
            'id' => (string) $lesson->id,
            'reference' => $lesson->reference,
            'instructor_id' => $lesson->instructor_id === null ? null : (string) $lesson->instructor_id,
            'requested_instructor_id' => $lesson->requested_instructor_id === null ? null : (string) $lesson->requested_instructor_id,
            'is_nominated' => $lesson->is_nominated,
            'assignment_method' => $lesson->assignment_method->value,
            'discipline' => $lesson->discipline->value,
            'language_code' => $lesson->language_code,
            'start_time' => $lesson->start_time,
            'end_time' => $lesson->end_time,
            'slot_minutes' => $lesson->slot_minutes,
            'duration_minutes' => $lesson->duration_minutes,
            'participants' => $lesson->participants,
            'customer_name' => $lesson->customer_name,
            'status' => $lesson->status->value,
            'invoiced' => $lesson->invoice_id !== null,
        ];
    }
}
