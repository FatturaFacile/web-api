<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Modules\Hospitality\Http\Requests\LodgingRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\Accommodation;
use Modules\Hospitality\Models\RatePlan;
use Modules\Hospitality\Models\RoomType;
use Modules\Hospitality\Services\LodgingService;
use OpenApi\Attributes as OA;

/**
 * Lodging catalog: room types, accommodations (physical rooms), seasonal rate plans, availability and price quotes.
 */
final class LodgingCatalogController extends Controller
{
    public function __construct(private readonly LodgingService $lodging) {}

    #[OA\Get(path: '/api/v1/hospitality/room-types', operationId: 'listHospitalityRoomTypes', description: 'Room types by code.', summary: 'List room types', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], responses: [
        new OA\Response(response: 200, description: 'Room types', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function roomTypes(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RoomType::class);

        return HospitalityResource::collection(RoomType::query()->orderBy('code')->gridPaginate(RequestedPerPage::resolve(100)));
    }

    #[OA\Post(path: '/api/v1/hospitality/room-types', operationId: 'storeHospitalityRoomType', description: 'Defines a room type with its capacity and base nightly rate. The code is unique in the company.', summary: 'Create a room type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRoomTypeRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Room type created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeRoomType(LodgingRequest $request): JsonResponse
    {
        $this->authorize('manage', RoomType::class);
        $this->assertCodeFree(RoomType::class, (string) $request->validated('code'), null);

        return (new HospitalityResource(RoomType::create($request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/hospitality/room-types/{id}', operationId: 'updateHospitalityRoomType', description: 'Changes a room type.', summary: 'Update a room type', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRoomTypeRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Room type updated', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function updateRoomType(LodgingRequest $request, string $id): HospitalityResource
    {
        $type = RoomType::query()->findOrFail($id);
        $this->authorize('manage', $type);
        $this->assertCodeFree(RoomType::class, (string) ($request->validated('code') ?? $type->code), $type->id);
        $type->update($request->validated());

        return new HospitalityResource($type->refresh());
    }

    #[OA\Get(path: '/api/v1/hospitality/accommodations', operationId: 'listHospitalityAccommodations', description: 'Physical rooms by number. Filters: room_type_id, housekeeping_status.', summary: 'List accommodations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'room_type_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'housekeeping_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['clean', 'dirty', 'inspected'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Accommodations', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function accommodations(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Accommodation::class);

        return HospitalityResource::collection(
            Accommodation::query()
                ->when($request->filled('room_type_id'), static fn ($query) => $query->where('room_type_id', (string) $request->query('room_type_id')))
                ->when($request->filled('housekeeping_status'), static fn ($query) => $query->where('housekeeping_status', (string) $request->query('housekeeping_status')))
                ->orderBy('number')->gridPaginate(RequestedPerPage::resolve(100))
        );
    }

    #[OA\Post(path: '/api/v1/hospitality/accommodations', operationId: 'storeHospitalityAccommodation', description: 'Registers a physical room of a room type. The number is unique in the company.', summary: 'Create an accommodation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityAccommodationRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Accommodation created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeAccommodation(LodgingRequest $request): JsonResponse
    {
        $this->authorize('manage', Accommodation::class);
        $this->assertCodeFree(Accommodation::class, (string) $request->validated('number'), null, 'number');

        return (new HospitalityResource(Accommodation::create($request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/hospitality/accommodations/{id}', operationId: 'updateHospitalityAccommodation', description: 'Changes a room: number, floor, out-of-service flag or housekeeping status.', summary: 'Update an accommodation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityAccommodationRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Accommodation updated', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function updateAccommodation(LodgingRequest $request, string $id): HospitalityResource
    {
        $room = Accommodation::query()->findOrFail($id);
        $this->authorize('update', $room);
        $room->update($request->validated());

        return new HospitalityResource($room->refresh());
    }

    #[OA\Get(path: '/api/v1/hospitality/rate-plans', operationId: 'listHospitalityRatePlans', description: 'Seasonal rate plans, highest priority first.', summary: 'List rate plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], responses: [
        new OA\Response(response: 200, description: 'Rate plans', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function ratePlans(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', RatePlan::class);

        return HospitalityResource::collection(RatePlan::query()->orderByDesc('priority')->orderBy('code')->gridPaginate(RequestedPerPage::resolve(100)));
    }

    #[OA\Post(path: '/api/v1/hospitality/rate-plans', operationId: 'storeHospitalityRatePlan', description: 'Defines a seasonal price over a date range for one room type (or every type when room_type_id is empty). With several plans covering a night the highest priority wins.', summary: 'Create a rate plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRatePlanRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Rate plan created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeRatePlan(LodgingRequest $request): JsonResponse
    {
        $this->authorize('manage', RatePlan::class);
        $this->assertCodeFree(RatePlan::class, (string) $request->validated('code'), null);

        return (new HospitalityResource(RatePlan::create($request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Put(path: '/api/v1/hospitality/rate-plans/{id}', operationId: 'updateHospitalityRatePlan', description: 'Changes a rate plan. Stays already booked keep the nightly rates they were booked at.', summary: 'Update a rate plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRatePlanRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Rate plan updated', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function updateRatePlan(LodgingRequest $request, string $id): HospitalityResource
    {
        $plan = RatePlan::query()->findOrFail($id);
        $this->authorize('manage', $plan);
        $this->assertCodeFree(RatePlan::class, (string) ($request->validated('code') ?? $plan->code), $plan->id);
        $plan->update($request->validated());

        return new HospitalityResource($plan->refresh());
    }

    #[OA\Get(path: '/api/v1/hospitality/stay-availability', operationId: 'getHospitalityStayAvailability', description: 'Rooms of a type free on every night from check-in (inclusive) to check-out (exclusive), with the booked and free count of each night.', summary: 'Room availability for an interval', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'room_type_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Availability', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityDataResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function availability(LodgingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', RoomType::class);
        $validated = $request->validated();

        return response()->json(['data' => $this->lodging->availability(RoomType::query()->findOrFail($validated['room_type_id']), $validated['from'], $validated['to'])]);
    }

    #[OA\Get(path: '/api/v1/hospitality/stay-quote', operationId: 'getHospitalityStayQuote', description: 'Nightly rates and the total of an interval: the chosen rate plan (it must cover every night and its minimum stay), else the best plan of each night, else the base rate.', summary: 'Price of a stay', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'room_type_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'rate_plan_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Quote', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityDataResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function quote(LodgingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', RoomType::class);
        $validated = $request->validated();

        return response()->json(['data' => $this->lodging->quote(RoomType::query()->findOrFail($validated['room_type_id']), $validated['from'], $validated['to'], $validated['rate_plan_id'] ?? null)]);
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function assertCodeFree(string $model, string $value, ?string $ignoreId, string $column = 'code'): void
    {
        $taken = $model::query()->where($column, $value)->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages([$column => ["A record with this {$column} exists."]]);
        }
    }
}
