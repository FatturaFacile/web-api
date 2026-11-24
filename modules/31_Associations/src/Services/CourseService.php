<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\CourseEnrolmentStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\CourseEnrolment;
use Modules\Associations\Models\CourseInstructor;
use Modules\Associations\Models\Member;

/**
 * Who takes part in a course. Every change of the participants locks the course row, so that the last free place goes
 * to one request only, and the course closes itself when it is full.
 */
final class CourseService
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly FeeService $fees,
    ) {}

    public function enrol(Course $course, Member $member): CourseEnrolment
    {
        return DB::transaction(function () use ($course, $member): CourseEnrolment {
            /** @var Course $locked */
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Course::Active) {
                throw AssociationException::conflict(__('associations.course_not_active'));
            }

            $alreadyIn = CourseEnrolment::query()->where('course_id', $locked->id)->where('member_id', $member->id)
                ->where('status', '!=', CourseEnrolmentStatus::Withdrawn)->exists();

            if ($alreadyIn) {
                throw AssociationException::conflict(__('associations.course_already_enrolled'));
            }

            $this->certificates->assertCovered($member, $locked->discipline_id, $locked->required_certificate);

            $enrolled = $locked->enrolledCount();
            $isFull = $locked->capacity !== null && $enrolled >= $locked->capacity;

            if (! $locked->registrations_open && ! $isFull) {
                throw AssociationException::conflict(__('associations.course_closed'));
            }

            if ($isFull && ! $locked->waitlist_enabled) {
                throw AssociationException::conflict(__('associations.course_full'));
            }

            $enrolment = CourseEnrolment::create([
                'course_id' => $locked->id,
                'member_id' => $member->id,
                'status' => $isFull ? CourseEnrolmentStatus::Waitlisted : CourseEnrolmentStatus::Enrolled,
                'waitlist_position' => $isFull ? $this->nextPosition($locked) : null,
                'enrolled_on' => CarbonImmutable::today(),
            ]);

            if (! $isFull) {
                $this->assignFee($locked, $member);

                if ($locked->auto_close_when_full && $locked->capacity !== null && $enrolled + 1 >= $locked->capacity) {
                    $locked->update(['registrations_open' => false]);
                }
            }

            return $enrolment;
        });
    }

    /**
     * A place that frees up goes to the first of the waiting list; a course with room and nobody waiting opens again.
     */
    public function withdraw(CourseEnrolment $enrolment): CourseEnrolment
    {
        return DB::transaction(function () use ($enrolment): CourseEnrolment {
            /** @var Course $course */
            $course = Course::query()->whereKey($enrolment->course_id)->lockForUpdate()->firstOrFail();

            /** @var CourseEnrolment $locked */
            $locked = CourseEnrolment::query()->whereKey($enrolment->id)->firstOrFail();

            if ($locked->status === CourseEnrolmentStatus::Withdrawn) {
                return $locked;
            }

            $hadPlace = $locked->status === CourseEnrolmentStatus::Enrolled;
            $locked->update(['status' => CourseEnrolmentStatus::Withdrawn, 'withdrawn_on' => CarbonImmutable::today(), 'waitlist_position' => null]);

            if ($hadPlace) {
                $this->fillFreedPlace($course);
            }

            return $locked;
        });
    }

    /**
     * @param  array{valid_from?: ?string, valid_to?: ?string}  $data
     */
    public function assignInstructor(Course $course, Member $instructor, array $data = []): CourseInstructor
    {
        if ($course->instructors()->where('member_id', $instructor->id)->exists()) {
            throw AssociationException::conflict(__('associations.instructor_already_assigned'));
        }

        return CourseInstructor::create([
            'course_id' => $course->id,
            'member_id' => $instructor->id,
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => $data['valid_to'] ?? null,
        ]);
    }

    private function fillFreedPlace(Course $course): void
    {
        /** @var ?CourseEnrolment $next */
        $next = CourseEnrolment::query()->with('member')->where('course_id', $course->id)
            ->where('status', CourseEnrolmentStatus::Waitlisted)->orderBy('waitlist_position')->first();

        if ($next !== null) {
            $next->update(['status' => CourseEnrolmentStatus::Enrolled, 'waitlist_position' => null]);
            $this->assignFee($course, $next->member);

            return;
        }

        if ($course->auto_close_when_full && ! $course->registrations_open) {
            $course->update(['registrations_open' => true]);
        }
    }

    private function assignFee(Course $course, Member $member): void
    {
        $plan = $course->feePlan;

        if ($plan !== null) {
            $this->fees->assign($member, $plan, [
                'course_id' => $course->id,
                'starts_on' => CarbonImmutable::today()->max($course->starts_on)->toDateString(),
                'ends_on' => $course->ends_on?->toDateString(),
            ]);
        }
    }

    private function nextPosition(Course $course): int
    {
        return (int) CourseEnrolment::query()->where('course_id', $course->id)->max('waitlist_position') + 1;
    }
}
