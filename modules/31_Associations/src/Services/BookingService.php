<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\Course;
use Modules\Associations\Models\ResourceBooking;

/**
 * Bookings of courts, rooms and other resources. Two confirmed bookings of a resource never overlap: the check runs under
 * a transaction lock of the resource, so it decides between concurrent requests too, on every database.
 */
final class BookingService
{
    public function __construct(private readonly CourseCalendar $calendar) {}

    /**
     * @param  array{member_id?: ?string, course_id?: ?string, title?: ?string}  $data
     */
    public function book(BookableResource $resource, CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $data = []): ResourceBooking
    {
        if (! $resource->is_active) {
            throw AssociationException::conflict(__('associations.resource_inactive'));
        }

        return DB::transaction(static function () use ($resource, $startsAt, $endsAt, $data): ResourceBooking {
            TransactionLock::acquire("association_resource_booking:{$resource->id}");

            $overlaps = ResourceBooking::query()
                ->where('resource_id', $resource->id)
                ->where('status', ResourceBooking::Confirmed)
                ->where('starts_at', '<', $endsAt)
                ->where('ends_at', '>', $startsAt)
                ->exists();

            if ($overlaps) {
                throw AssociationException::conflict(__('associations.resource_already_booked'));
            }

            return ResourceBooking::create([
                'resource_id' => $resource->id,
                'member_id' => $data['member_id'] ?? null,
                'course_id' => $data['course_id'] ?? null,
                'title' => $data['title'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => ResourceBooking::Confirmed,
            ]);
        });
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
