<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\TravelExpense\Http\Controllers\Concerns\InteractsWithTravel;
use Modules\TravelExpense\Http\Requests\TripRequest;
use Modules\TravelExpense\Http\Resources\TripResource;
use Modules\TravelExpense\Models\TravelTrip;
use Modules\TravelExpense\Policies\TravelTripPolicy;
use Modules\TravelExpense\Services\TripService;
use OpenApi\Attributes as OA;

/**
 * Business trip requests of the employees of the company.
 */
final class TripController extends Controller
{
    use InteractsWithTravel;

    public function __construct(private readonly TripService $trips, private readonly TravelTripPolicy $policy) {}

    #[OA\Get(path: '/api/v1/travel/trips', operationId: 'listTravelTrips', description: 'Paginated trips, newest departure first. Members see their own trips; owners, administrators, HR and accountants see every trip and can filter by user_id. Filters: status, user_id, search (number, destination, purpose), date_from and date_to (overlap with the trip dates).', summary: 'List business trips', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'submitted', 'approved', 'rejected', 'completed', 'reconciled'])),
        new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Trips', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TravelTrip'))])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TravelTrip::class);
        $manager = $this->policy->isManager($request->user());
        $search = trim((string) $request->query('search', ''));

        return TripResource::collection(
            TravelTrip::query()
                ->with('employee')
                ->where('tenant_id', $this->tenantId())
                ->when(! $manager, fn ($query) => $query->where('user_id', $this->userId()))
                ->when($manager && $request->filled('user_id'), static fn ($query) => $query->where('user_id', (string) $request->query('user_id')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('date_from'), static fn ($query) => $query->whereDate('end_date', '>=', (string) $request->query('date_from')))
                ->when($request->filled('date_to'), static fn ($query) => $query->whereDate('start_date', '<=', (string) $request->query('date_to')))
                ->when($search !== '', static fn ($query) => $query->where(static fn ($sub) => $sub->whereLike('number', "%{$search}%")->orWhereLike('destination_city', "%{$search}%")->orWhereLike('purpose', "%{$search}%")))
                ->orderByDesc('start_date')
                ->orderByDesc('number')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/travel/trips', operationId: 'storeTravelTrip', description: 'Creates a trip request in draft. The number is progressive per company and year (TR-2027-0001). Currency and rate jurisdiction default from the country of the company. A member requests trips for themselves; owners, administrators, HR and accountants can name another employee in user_id.', summary: 'Request a business trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelTripRequest')), tags: ['Travel'], responses: [
        new OA\Response(response: 201, description: 'Trip created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function store(TripRequest $request): JsonResponse
    {
        $this->authorize('create', TravelTrip::class);
        $data = $request->validated();
        $employeeId = (string) ($data['user_id'] ?? $this->userId());

        if ($employeeId !== $this->userId() && ! $this->policy->isManager($request->user())) {
            throw ValidationException::withMessages(['user_id' => __('travel_errors.trip.own_only')]);
        }

        $trip = $this->trips->create($this->tenantId(), $data, $employeeId);

        return (new TripResource($trip->load('employee')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/travel/trips/{id}', operationId: 'showTravelTrip', description: 'A trip with its employee, itinerary, per-diem days and mileage claims.', summary: 'Show a business trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
    ])]
    public function show(string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('view', $trip);

        return new TripResource($trip->load(['employee', 'legs', 'perDiemDays', 'mileageClaims']));
    }

    #[OA\Put(path: '/api/v1/travel/trips/{id}', operationId: 'updateTravelTrip', description: 'Changes a trip request. Only a draft can be changed (a rejected trip is reopened first). Changing dates, times, destination country, jurisdiction or currency discards the calculated per-diem days.', summary: 'Update a business trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TravelTripRequest')), tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 200, description: 'Trip updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TravelTrip')])),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function update(TripRequest $request, string $id): TripResource
    {
        $trip = $this->findTrip($id);
        $this->authorize('update', $trip);
        $data = $request->validated();
        unset($data['user_id']);

        return new TripResource($this->trips->update($trip, $data)->load('employee'));
    }

    #[OA\Delete(path: '/api/v1/travel/trips/{id}', operationId: 'deleteTravelTrip', description: 'Deletes a draft trip. A submitted trip is withdrawn first; any other trip stays on record.', summary: 'Delete a draft business trip', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Travel'], parameters: [new OA\Parameter(ref: '#/components/parameters/TravelId')], responses: [
        new OA\Response(response: 204, description: 'Trip deleted'),
        new OA\Response(ref: '#/components/responses/TravelUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TravelForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TravelNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TravelUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $trip = $this->findTrip($id);
        $this->authorize('delete', $trip);
        $this->trips->delete($trip);

        return response()->noContent();
    }
}
