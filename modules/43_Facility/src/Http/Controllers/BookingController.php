<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\BookingCheckRequest;
use Modules\Facility\Http\Requests\BookingRequest;
use Modules\Facility\Http\Requests\CancelBookingRequest;
use Modules\Facility\Http\Resources\BookingResource;
use Modules\Facility\Models\FacilityBooking;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Policies\FacilityPolicy;
use Modules\Facility\Services\BookingService;

/**
 * Room and desk bookings: the calendar list, the dry-run check, booking, check-in and cancellation. Operations are
 * documented in FacilityBookingOpenApi.
 */
final class BookingController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilityBooking::class);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'space_id' => ['nullable', 'uuid'],
            'building_id' => ['nullable', 'uuid'],
            'member_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(FacilityBooking::Statuses)],
            'mine' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        return BookingResource::collection(
            FacilityBooking::query()
                ->with(['user:id,name', 'space:id,building_id,kind,code,name'])
                ->when(isset($filters['from']), fn ($query) => $query->where('ends_at', '>', $this->dayStart($filters['from'])))
                ->when(isset($filters['to']), fn ($query) => $query->where('starts_at', '<', $this->dayStart($filters['to'])->addDay()))
                ->when(isset($filters['space_id']), static fn ($query) => $query->where('space_id', $filters['space_id']))
                ->when(isset($filters['building_id']), static fn ($query) => $query->whereIn('space_id', FacilitySpace::query()->where('building_id', $filters['building_id'])->select('id')))
                ->when(isset($filters['member_id']), static fn ($query) => $query->where('user_id', $filters['member_id']))
                ->when($request->boolean('mine'), fn ($query) => $query->where('user_id', $this->actorId()))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when($request->boolean('active'), static fn ($query) => $query->whereIn('status', FacilityBooking::OccupyingStatuses))
                ->orderBy('starts_at')
                ->gridPaginate($this->perPage())
        );
    }

    public function check(BookingCheckRequest $request): JsonResponse
    {
        $this->authorize('create', FacilityBooking::class);
        $data = $request->validated();
        $space = FacilitySpace::query()->findOrFail($data['space_id']);
        $result = $this->bookings->check(
            $space,
            $this->bookingUserId($data['user_id'] ?? null),
            $this->parseInstant($data['starts_at']),
            $this->parseInstant($data['ends_at']),
            isset($data['attendees']) ? (int) $data['attendees'] : null,
            $data['booking_id'] ?? null,
        );

        return response()->json(['data' => [
            'available' => $result['available'],
            'violations' => $result['violations'],
            'conflicts' => BookingResource::collection($result['conflicts'])->resolve(),
            'rules' => $this->bookings->rulesFor($space->kind),
        ]]);
    }

    public function store(BookingRequest $request): JsonResponse
    {
        $this->authorize('create', FacilityBooking::class);
        $data = $request->validated();
        $space = FacilitySpace::query()->findOrFail($data['space_id']);

        $booking = $this->bookings->book(
            $space,
            $this->bookingUserId($data['user_id'] ?? null),
            $this->parseInstant($data['starts_at']),
            $this->parseInstant($data['ends_at']),
            ['title' => $data['title'] ?? null, 'attendees' => $data['attendees'] ?? null],
            $this->actorId(),
        );

        return (new BookingResource($booking->load(['user:id,name', 'space:id,building_id,kind,code,name'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): BookingResource
    {
        $booking = FacilityBooking::query()->with(['user:id,name', 'space:id,building_id,kind,code,name'])->findOrFail($id);
        $this->authorize('view', $booking);

        return new BookingResource($booking);
    }

    public function checkIn(string $id): BookingResource
    {
        $booking = FacilityBooking::query()->findOrFail($id);
        $this->authorize('checkIn', $booking);

        return new BookingResource($this->bookings->checkIn($booking)->load(['user:id,name', 'space:id,building_id,kind,code,name']));
    }

    public function cancel(CancelBookingRequest $request, string $id): BookingResource
    {
        $booking = FacilityBooking::query()->findOrFail($id);
        $this->authorize('cancelBooking', $booking);

        return new BookingResource($this->bookings->cancel($booking, $request->validated('reason'))->load(['user:id,name', 'space:id,building_id,kind,code,name']));
    }

    /**
     * Members book for themselves; only facility managers book for somebody else.
     */
    private function bookingUserId(?string $requested): string
    {
        if ($requested === null || $requested === $this->actorId()) {
            return $this->actorId();
        }

        if (! app(FacilityPolicy::class)->manage($this->actor())) {
            throw ValidationException::withMessages(['user_id' => [__('facility_errors.booking.own_only')]]);
        }

        return $requested;
    }
}
