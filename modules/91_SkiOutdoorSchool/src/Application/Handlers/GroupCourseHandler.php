<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\SkiOutdoorSchool\Application\Commands\EnrollStudentCommand;
use Modules\SkiOutdoorSchool\Application\Services\CommissionRecorder;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Domain\Enums\BookingSource;
use Modules\SkiOutdoorSchool\Domain\Enums\CommissionSource;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\EnrollmentStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemStatus;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\LevelReassignmentService;
use Modules\SkiOutdoorSchool\Domain\ValueObjects\TimeRange;
use Modules\SkiOutdoorSchool\Events\StudentsReassigned;
use Modules\SkiOutdoorSchool\Models\CourseAttendance;
use Modules\SkiOutdoorSchool\Models\CourseEnrollment;
use Modules\SkiOutdoorSchool\Models\CourseLevel;
use Modules\SkiOutdoorSchool\Models\CourseTransfer;
use Modules\SkiOutdoorSchool\Models\GroupCourse;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\PackageItem;

/**
 * Weekly group courses by technical level: the course (class) itself, enrolments with the capacity check, the batch reassignment of students
 * between level groups after the Monday evaluation (attendance history is kept, the capacity of every group is checked on the whole batch),
 * daily attendance and the closing of the course, which records the fee of the instructor.
 */
final class GroupCourseHandler
{
    public function __construct(
        private readonly SchoolSettingsService $settings,
        private readonly PackageHandler $packages,
        private readonly LevelReassignmentService $reassignment,
        private readonly CommissionRecorder $commissions,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     *
     * @throws SkiSchoolException course_not_found, level_not_found, instructor_not_found, invalid_period, capacity_below_enrolled, instructor_course_conflict, course_locked
     */
    public function save(array $data, ?string $id = null): GroupCourse
    {
        return DB::transaction(function () use ($data, $id): GroupCourse {
            $course = $id === null ? new GroupCourse : (GroupCourse::query()->whereKey($id)->lockForUpdate()->first() ?? throw SkiSchoolException::because('course_not_found', [], 404));
            CourseLevel::query()->find($data['level_id']) ?? throw SkiSchoolException::because('level_not_found', [], 404);
            Discipline::from((string) $data['discipline']);
            $start = Carbon::parse((string) $data['week_start'])->startOfDay();
            $end = isset($data['end_date']) && $data['end_date'] !== '' ? Carbon::parse((string) $data['end_date'])->startOfDay() : $start->copy()->addDays(5);

            if ($end->lt($start) || $start->diffInDays($end) > 13) {
                throw SkiSchoolException::because('invalid_period');
            }

            if ($course->exists && $course->status === CourseStatus::COMPLETED) {
                throw SkiSchoolException::because('course_locked', ['status' => $course->status->value]);
            }

            $capacity = (int) $data['capacity'];

            if ($course->exists && $capacity < $course->activeEnrollmentCount()) {
                throw SkiSchoolException::because('capacity_below_enrolled', ['enrolled' => $course->activeEnrollmentCount()]);
            }

            $instructorId = isset($data['instructor_id']) && $data['instructor_id'] !== '' ? (string) $data['instructor_id'] : null;
            $startTime = (string) $data['daily_start_time'];
            $duration = (int) ($data['duration_minutes'] ?? 120);

            if ($instructorId !== null) {
                Instructor::query()->find($instructorId) ?? throw SkiSchoolException::because('instructor_not_found', [], 404);
                $this->assertNoCourseOverlap($instructorId, $start, $end, TimeRange::fromStart($startTime, $duration), $course->exists ? (string) $course->id : null);
            }

            $days = $start->diffInDays($end) + 1;
            $course->fill([
                'code' => (string) ($data['code'] ?? ($course->exists ? $course->code : $this->settings->reference('GC'))),
                'discipline' => (string) $data['discipline'],
                'level_id' => (string) $data['level_id'],
                'instructor_id' => $instructorId,
                'language_code' => strtoupper((string) $data['language_code']),
                'week_start' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'daily_start_time' => $startTime,
                'duration_minutes' => $duration,
                'capacity' => $capacity,
                'min_participants' => (int) ($data['min_participants'] ?? 3),
                'meeting_point' => $data['meeting_point'] ?? $this->settings->current()->meeting_point,
                'price_net' => (float) ($data['price_net'] ?? round($days * (float) config('ski_school.group_course_day_price'), 2)),
                'vat_regime' => (string) ($data['vat_regime'] ?? $this->settings->vatRegime()->value),
                'status' => (string) ($data['status'] ?? ($course->exists ? $course->status->value : CourseStatus::OPEN->value)),
                'notes' => $data['notes'] ?? null,
            ]);
            $course->save();

            return $course->load(['level', 'instructor'])->refresh();
        });
    }

    /**
     * @throws SkiSchoolException course_not_found, course_locked, invalid_status_transition
     */
    public function setStatus(string $courseId, CourseStatus $status): GroupCourse
    {
        return DB::transaction(function () use ($courseId, $status): GroupCourse {
            $course = GroupCourse::query()->whereKey($courseId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('course_not_found', [], 404);

            if (in_array($course->status, [CourseStatus::COMPLETED, CourseStatus::CANCELLED], true)) {
                throw SkiSchoolException::because('course_locked', ['status' => $course->status->value]);
            }

            if ($status === CourseStatus::COMPLETED) {
                return $this->complete($course);
            }

            if ($status === CourseStatus::PLANNED) {
                throw SkiSchoolException::because('invalid_status_transition');
            }

            $course->update(['status' => $status->value]);

            return $course->refresh();
        });
    }

    /**
     * @throws SkiSchoolException course_not_found, course_closed, course_full
     */
    public function enroll(EnrollStudentCommand $command): CourseEnrollment
    {
        return DB::transaction(function () use ($command): CourseEnrollment {
            $course = GroupCourse::query()->whereKey($command->courseId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('course_not_found', [], 404);

            if (! $course->status->acceptsEnrolments()) {
                throw SkiSchoolException::because('course_closed', ['status' => $course->status->value]);
            }

            if ($course->activeEnrollmentCount() >= $course->capacity) {
                throw SkiSchoolException::because('course_full', ['capacity' => $course->capacity]);
            }

            $enrollment = CourseEnrollment::query()->create([
                'class_id' => (string) $course->id,
                'initial_class_id' => (string) $course->id,
                'contact_id' => $command->contactId,
                'student_name' => $command->studentName,
                'birth_date' => $command->birthDate,
                'guardian_name' => $command->guardianName,
                'guardian_email' => $command->guardianEmail,
                'emergency_contact_name' => $command->emergencyContactName,
                'emergency_contact_phone' => $command->emergencyContactPhone,
                'bib_number' => $this->nextBib((string) $course->id),
                'price_net' => $command->priceNet ?? (float) $course->price_net,
                'status' => EnrollmentStatus::ENROLLED->value,
                'source' => BookingSource::from($command->source)->value,
                'booking_reference' => $this->settings->reference('GCE'),
                'notes' => $command->notes,
            ]);

            if ($command->items !== []) {
                $this->packages->replaceForEnrollment((string) $enrollment->id, $command->items);
            }

            return $enrollment->refresh();
        });
    }

    /**
     * @throws SkiSchoolException enrollment_not_found, enrollment_invoiced
     */
    public function withdraw(string $enrollmentId): CourseEnrollment
    {
        return DB::transaction(function () use ($enrollmentId): CourseEnrollment {
            $enrollment = CourseEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first() ?? throw SkiSchoolException::because('enrollment_not_found', [], 404);

            if ($enrollment->invoice_id !== null) {
                throw SkiSchoolException::because('enrollment_invoiced');
            }

            $enrollment->update(['status' => EnrollmentStatus::WITHDRAWN->value]);
            PackageItem::query()->where('enrollment_id', $enrollment->id)->update(['status' => PackageItemStatus::CANCELLED->value]);

            return $enrollment->refresh();
        });
    }

    /**
     * Moves students between level groups (after the Monday on-snow evaluation). The whole batch is validated against the capacity of every
     * group before anything is written; the enrolment, its attendance history and its package follow the student.
     *
     * @param  list<array{enrollment_id: string, to_class_id: string}>  $moves
     * @return list<CourseEnrollment>
     *
     * @throws SkiSchoolException enrollment_not_found, enrollment_not_active, same_group, group_not_found, group_closed, group_mismatch, capacity_exceeded, duplicate_move
     */
    public function transfer(array $moves, ?string $reason, ?string $userId): array
    {
        return DB::transaction(function () use ($moves, $reason, $userId): array {
            $enrollments = CourseEnrollment::query()->whereIn('id', array_column($moves, 'enrollment_id'))->lockForUpdate()->get()->keyBy('id');
            $plan = [];

            foreach ($moves as $move) {
                $enrollment = $enrollments->get($move['enrollment_id']) ?? throw SkiSchoolException::because('enrollment_not_found', [], 404);

                if ($enrollment->status !== EnrollmentStatus::ENROLLED) {
                    throw SkiSchoolException::because('enrollment_not_active', ['student' => $enrollment->student_name]);
                }

                $plan[] = ['enrollment_id' => (string) $enrollment->id, 'from_class_id' => (string) $enrollment->class_id, 'to_class_id' => $move['to_class_id']];
            }

            $classIds = array_values(array_unique([...array_column($plan, 'from_class_id'), ...array_column($plan, 'to_class_id')]));
            sort($classIds);
            $courses = GroupCourse::query()->whereIn('id', $classIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $groups = [];

            foreach ($courses as $id => $course) {
                $groups[(string) $id] = [
                    'code' => $course->code,
                    'capacity' => $course->capacity,
                    'enrolled' => $course->activeEnrollmentCount(),
                    'accepts' => $course->status->acceptsEnrolments(),
                    'discipline' => $course->discipline->value,
                    'week_start' => $course->week_start->toDateString(),
                ];
            }

            $this->reassignment->validate($groups, $plan);
            $moved = [];

            foreach ($plan as $move) {
                $enrollment = $enrollments->get($move['enrollment_id']);
                $enrollment->update(['class_id' => $move['to_class_id'], 'bib_number' => $this->nextBib($move['to_class_id'], (string) $enrollment->id)]);

                CourseTransfer::query()->create([
                    'enrollment_id' => (string) $enrollment->id,
                    'from_class_id' => $move['from_class_id'],
                    'to_class_id' => $move['to_class_id'],
                    'reason' => $reason,
                    'transferred_by' => $userId,
                    'transferred_at' => now(),
                ]);
                $moved[] = $enrollment->refresh();
            }

            event(new StudentsReassigned(array_column($plan, 'enrollment_id'), (string) $reason));

            return $moved;
        });
    }

    /**
     * Presence of the students of a group on one day of the course. Past presences of students who later moved stay with the group they were in.
     *
     * @param  list<array{enrollment_id: string, present: bool}>  $entries
     * @return list<CourseAttendance>
     *
     * @throws SkiSchoolException course_not_found, date_outside_course, enrollment_not_found
     */
    public function recordAttendance(string $courseId, string $date, array $entries): array
    {
        return DB::transaction(function () use ($courseId, $date, $entries): array {
            $course = GroupCourse::query()->find($courseId) ?? throw SkiSchoolException::because('course_not_found', [], 404);

            if ($date < $course->week_start->toDateString() || $date > $course->end_date->toDateString()) {
                throw SkiSchoolException::because('date_outside_course');
            }

            $valid = CourseEnrollment::query()->where('class_id', $courseId)->where('status', EnrollmentStatus::ENROLLED->value)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
            $saved = [];

            foreach ($entries as $entry) {
                if (! in_array($entry['enrollment_id'], $valid, true)) {
                    throw SkiSchoolException::because('enrollment_not_found', [], 404);
                }

                $row = CourseAttendance::query()->where('enrollment_id', $entry['enrollment_id'])->whereDate('attendance_date', $date)->first();

                if ($row === null) {
                    $row = CourseAttendance::query()->create(['enrollment_id' => $entry['enrollment_id'], 'class_id' => $courseId, 'attendance_date' => $date, 'present' => $entry['present']]);
                } else {
                    $row->update(['present' => $entry['present'], 'class_id' => $courseId]);
                }

                $saved[] = $row;
            }

            return $saved;
        });
    }

    private function complete(GroupCourse $course): GroupCourse
    {
        $course->update(['status' => CourseStatus::COMPLETED->value]);

        if ($course->instructor_id !== null) {
            $days = (int) $course->week_start->diffInDays($course->end_date) + 1;
            $this->commissions->record(Instructor::query()->findOrFail($course->instructor_id), CommissionSource::COURSE, (string) $course->id, $course->end_date->toDateString(), $days * $course->duration_minutes);
        }

        return $course->refresh();
    }

    private function nextBib(string $courseId, ?string $keepFor = null): int
    {
        $used = CourseEnrollment::query()->where('class_id', $courseId)->where('status', EnrollmentStatus::ENROLLED->value)->whereNotNull('bib_number')->pluck('bib_number')->map(static fn (mixed $bib): int => (int) $bib)->all();

        if ($keepFor !== null) {
            $own = CourseEnrollment::query()->whereKey($keepFor)->value('bib_number');

            if ($own !== null && ! in_array((int) $own, $used, true)) {
                return (int) $own;
            }
        }

        return ($used === [] ? 0 : max($used)) + 1;
    }

    private function assertNoCourseOverlap(string $instructorId, Carbon $start, Carbon $end, TimeRange $range, ?string $ignoreId): void
    {
        $others = GroupCourse::query()
            ->where('instructor_id', $instructorId)
            ->when($ignoreId, static fn ($query, string $id) => $query->where('id', '!=', $id))
            ->where('status', '!=', CourseStatus::CANCELLED->value)
            ->whereDate('week_start', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get(['daily_start_time', 'duration_minutes']);

        foreach ($others as $other) {
            if (TimeRange::fromStart((string) $other->daily_start_time, (int) $other->duration_minutes)->overlaps($range)) {
                throw SkiSchoolException::because('instructor_course_conflict');
            }
        }
    }
}
