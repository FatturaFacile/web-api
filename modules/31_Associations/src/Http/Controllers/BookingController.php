<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\StoreBookingRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\BookableResource;
use Modules\Associations\Models\ResourceBooking;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\BookingService;

/**
 * Bookings of courts, rooms and other resources.
 */
final class BookingController extends AssociationController
{
    public function __construct(private readonly BookingService $bookings) {}

    #[AssociationGet('/api/v1/associations/bookings', 'listAssociationBookings', 'List the bookings', query: ['resource_id', 'from', 'to'])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ResourceBooking::class);

        $bookings = ResourceBooking::query()
            ->where('status', ResourceBooking::Confirmed)
            ->when($request->filled('resource_id'), static fn ($query) => $query->where('resource_id', (string) $request->query('resource_id')))
            ->when($request->filled('from'), static fn ($query) => $query->where('ends_at', '>', (string) $request->query('from')))
            ->when($request->filled('to'), static fn ($query) => $query->where('starts_at', '<', (string) $request->query('to')))
            ->orderBy('starts_at')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($bookings);
    }

    #[AssociationPost('/api/v1/associations/bookings', 'createAssociationBooking', 'Book a resource. Overlapping bookings are refused', 'AssociationBookingRequest')]
    public function store(StoreBookingRequest $request): AssociationRecordResource
    {
        $this->authorize('create', ResourceBooking::class);

        return new AssociationRecordResource($this->bookings->book(
            BookableResource::query()->findOrFail($request->validated('resource_id')),
            CarbonImmutable::parse($request->validated('starts_at')),
            CarbonImmutable::parse($request->validated('ends_at')),
            $request->safe()->only(['member_id', 'course_id', 'title']),
        ));
    }

    #[AssociationPost('/api/v1/associations/bookings/{id}/cancel', 'cancelAssociationBooking', 'Cancel a booking and free the resource')]
    public function cancel(string $id): AssociationRecordResource
    {
        $booking = ResourceBooking::query()->findOrFail($id);
        $this->authorize('update', $booking);

        return new AssociationRecordResource($this->bookings->cancel($booking));
    }
}
