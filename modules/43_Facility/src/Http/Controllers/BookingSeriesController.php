<?php

declare(strict_types=1);

namespace Modules\Facility\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Http\Controllers\Concerns\InteractsWithFacility;
use Modules\Facility\Http\Requests\BookingSeriesRequest;
use Modules\Facility\Http\Requests\CancelBookingRequest;
use Modules\Facility\Http\Resources\BookingSeriesResource;
use Modules\Facility\Models\FacilityBookingSeries;
use Modules\Facility\Models\FacilitySpace;
use Modules\Facility\Policies\FacilityPolicy;
use Modules\Facility\Services\BookingService;

/**
 * Recurring bookings. Operations are documented in FacilityBookingOpenApi.
 */
final class BookingSeriesController extends Controller
{
    use InteractsWithFacility;

    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FacilityBookingSeries::class);

        return BookingSeriesResource::collection(
            FacilityBookingSeries::query()
                ->when($request->boolean('mine'), fn ($query) => $query->where('user_id', $this->actorId()))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderByDesc('start_date')
                ->gridPaginate($this->perPage())
        );
    }

    public function store(BookingSeriesRequest $request): JsonResponse
    {
        $this->authorize('create', FacilityBookingSeries::class);
        $data = $request->validated();
        $space = FacilitySpace::query()->findOrFail($data['space_id']);
        $userId = $data['user_id'] ?? $this->actorId();

        if ($userId !== $this->actorId() && ! app(FacilityPolicy::class)->manage($this->actor())) {
            throw ValidationException::withMessages(['user_id' => [__('facility_errors.booking.own_only')]]);
        }

        $result = $this->bookings->createSeries($space, $userId, $data, (bool) ($data['skip_conflicts'] ?? false), $this->actorId());

        return response()->json(['data' => [
            ...(new BookingSeriesResource($result['series']))->resolve(),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ]], Response::HTTP_CREATED);
    }

    public function show(string $id): BookingSeriesResource
    {
        $series = FacilityBookingSeries::query()->findOrFail($id);
        $this->authorize('view', $series);

        return new BookingSeriesResource($series);
    }

    public function cancel(CancelBookingRequest $request, string $id): JsonResponse
    {
        $series = FacilityBookingSeries::query()->findOrFail($id);

        abort_unless((string) $series->user_id === $this->actorId() || app(FacilityPolicy::class)->manage($this->actor(), $series), 403);

        $cancelled = $this->bookings->cancelSeries($series, $request->validated('reason'));

        return response()->json(['data' => [...(new BookingSeriesResource($series->refresh()))->resolve(), 'cancelled_bookings' => $cancelled]]);
    }
}
