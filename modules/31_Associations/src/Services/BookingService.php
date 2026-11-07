<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\ResourceBooking;

/**
 * Bookings of courts, rooms and other resources. Nothing here checks for overlaps: the exclusion constraint of the table
 * is the only judge, and it decides between concurrent requests too.
 */
final class BookingService
{
    /** PostgreSQL exclusion_violation. */
    private const string ExclusionViolation = '23P01';

    /**
     * PostgreSQL deadlock_detected. Concurrent overlapping inserts each wait for the other under an exclusion constraint,
     * and the database aborts one of them: the outcome is the same as a refused overlap.
     */
    private const string DeadlockDetected = '40P01';

    /** A session aborted by a deadlock retries, to get the real verdict of the constraint. */
    private const int Attempts = 3;

    public function __construct(private readonly CourseCalendar $calendar) {}

    /**
     * @param  array{member_id?: ?string, course_id?: ?string, title?: ?string}  $data
     */
    public function book(BookableResource $resource, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $data = []): ResourceBooking
    {
        if (! $resource->is_active) {
            throw AssociationException::conflict(__('associations.resource_inactive'));
        }

        try {
            return DB::transaction(static fn (): ResourceBooking => ResourceBooking::create([
                'resource_id' => $resource->id,
                'member_id' => $data['member_id'] ?? null,
                'course_id' => $data['course_id'] ?? null,
                'title' => $data['title'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => ResourceBooking::Confirmed,
            ]), self::Attempts);
        } catch (DeadlockException) {
            throw AssociationException::conflict(__('associations.resource_already_booked'));
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), [self::ExclusionViolation, self::DeadlockDetected], true)) {
                throw AssociationException::conflict(__('associations.resource_already_booked'));
            }

            throw $exception;
        }
    }

    public function cancel(ResourceBooking $booking): ResourceBooking
    {
        $booking->update(['status' => ResourceBooking::Cancelled]);

        return $booking;
    }

    /**
     * Reserves the resource of a course for its lessons in a period, all or nothing.
     *
     * @return int the bookings created
     */
    public function bookCourseLessons(Course $course, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return DB::transaction(function () use ($course, $from, $to): int {
            $count = 0;

            foreach ($this->calendar->occurrences($course, $from, $to) as $lesson) {
                if ($lesson['suspended'] || $lesson['resource_id'] === null) {
                    continue;
                }

                $resource = BookableResource::query()->findOrFail($lesson['resource_id']);

                $this->book(
                    $resource,
                    CarbonImmutable::parse("{$lesson['date']} {$lesson['starts_at']}"),
                    CarbonImmutable::parse("{$lesson['date']} {$lesson['ends_at']}"),
                    ['course_id' => $course->id, 'title' => $course->title],
                );

                $count++;
            }

            return $count;
        });
    }
}
