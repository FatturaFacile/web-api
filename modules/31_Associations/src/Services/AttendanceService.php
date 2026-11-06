<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Enums\FeeFrequency;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Attendance;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\FeeInstalment;
use Modules\Associations\Support\Money;

/**
 * Attendance per day. A presence feeds the fee of the course: it takes a lesson from a package or adds a charge per
 * attendance, and undoing the presence gives that back. Setting the same presence twice counts once.
 */
final class AttendanceService
{
    public function __construct(
        private readonly CourseCalendar $calendar,
        private readonly FeeService $fees,
    ) {}

    /**
     * @param  list<array{member_id: string, status: string}>  $entries
     * @param  array{member_id?: ?string, user_id?: ?string}  $recordedBy
     * @return list<array{member_id: string, status: string, package_exhausted: bool}>
     */
    public function record(Course $course, CarbonImmutable $day, array $entries, array $recordedBy): array
    {
        $this->assertLessonDay($course, $day);

        return DB::transaction(function () use ($course, $day, $entries, $recordedBy): array {
            $result = [];

            foreach ($entries as $entry) {
                $isEnrolled = CourseEnrolment::query()->where('course_id', $course->id)->where('member_id', $entry['member_id'])
                    ->where('status', CourseEnrolmentStatus::Enrolled)->exists();

                if (! $isEnrolled) {
                    throw new AssociationException(__('associations.attendance_not_enrolled'));
                }

                $result[] = $this->recordOne($course, $day, $entry['member_id'], $entry['status'], $recordedBy);
            }

            return $result;
        });
    }

    private function assertLessonDay(Course $course, CarbonImmutable $day): void
    {
        if ($day->isFuture()) {
            throw new AssociationException(__('associations.attendance_future'));
        }

        $state = $this->calendar->dayState($course, $day);

        if ($state !== 'lesson') {
            throw new AssociationException(__($state === 'suspended' ? 'associations.attendance_suspended' : 'associations.attendance_no_lesson'));
        }
    }

    /**
     * @param  array{member_id?: ?string, user_id?: ?string}  $recordedBy
     * @return array{member_id: string, status: string, package_exhausted: bool}
     */
    private function recordOne(Course $course, CarbonImmutable $day, string $memberId, string $status, array $recordedBy): array
    {
        /** @var ?Attendance $attendance */
        $attendance = Attendance::query()->where('course_id', $course->id)->where('member_id', $memberId)
            ->whereDate('attended_on', $day->toDateString())->lockForUpdate()->first();

        $wasPresent = $attendance?->status === Attendance::Present;
        $isPresent = $status === Attendance::Present;
        $exhausted = false;

        $attendance ??= new Attendance(['course_id' => $course->id, 'member_id' => $memberId, 'attended_on' => $day->toDateString()]);
        $attendance->status = $status;
        $attendance->recorded_by_member_id = $recordedBy['member_id'] ?? null;
        $attendance->recorded_by_user_id = $recordedBy['user_id'] ?? null;

        if ($isPresent && ! $wasPresent) {
            $exhausted = $this->charge($attendance, $course, $memberId, $day);
        } elseif ($wasPresent && ! $isPresent) {
            $this->refund($attendance);
        }

        $attendance->save();

        return ['member_id' => $memberId, 'status' => $status, 'package_exhausted' => $exhausted];
    }

    /**
     * @return bool true when the package had no lessons left
     */
    private function charge(Attendance $attendance, Course $course, string $memberId, CarbonImmutable $day): bool
    {
        /** @var ?FeeAssignment $assignment */
        $assignment = FeeAssignment::query()->with('plan')->where('member_id', $memberId)->where('course_id', $course->id)
            ->where('status', FeeAssignment::Active)
            ->where(static fn ($query) => $query->whereNotNull('lessons_remaining')->orWhereNotNull('per_attendance_amount'))
            ->first();

        if ($assignment === null) {
            return false;
        }

        $attendance->fee_assignment_id = $assignment->id;

        if ($assignment->lessons_remaining !== null) {
            $taken = FeeAssignment::query()->whereKey($assignment->id)->where('lessons_remaining', '>', 0)->decrement('lessons_remaining');

            return $taken === 0;
        }

        if ($assignment->plan?->frequency === FeeFrequency::PerAttendance || $assignment->per_attendance_amount !== null) {
            $attendance->fee_instalment_id = $this->fees->chargeAttendance($assignment, $day)->id;
        }

        return false;
    }

    private function refund(Attendance $attendance): void
    {
        if ($attendance->fee_assignment_id === null) {
            return;
        }

        /** @var ?FeeAssignment $assignment */
        $assignment = FeeAssignment::query()->whereKey($attendance->fee_assignment_id)->lockForUpdate()->first();

        if ($assignment === null) {
            return;
        }

        if ($assignment->lessons_remaining !== null && $assignment->lessons_remaining < $assignment->lessons_total) {
            $assignment->update(['lessons_remaining' => $assignment->lessons_remaining + 1]);
        }

        $instalment = $attendance->fee_instalment_id === null ? null : FeeInstalment::query()->find($attendance->fee_instalment_id);

        if ($instalment !== null && $instalment->status === InstalmentStatus::Open) {
            $instalment->update(['status' => InstalmentStatus::Cancelled]);
            $assignment->update([
                'gross_amount' => Money::sub((string) $assignment->gross_amount, (string) $instalment->amount),
                'net_amount' => Money::sub((string) $assignment->net_amount, (string) $instalment->amount),
            ]);
        }

        $attendance->fee_instalment_id = null;
    }
}
