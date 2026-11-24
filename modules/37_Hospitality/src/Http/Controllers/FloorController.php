<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Hospitality\Http\Requests\HospitalityRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\DiningTable;
use Modules\Hospitality\Models\Room;
use Modules\Hospitality\Services\HospitalityService;
use OpenApi\Attributes as OA;

/**
 * Rooms, tables and the room plan with the occupied tables.
 */
final class FloorController extends Controller
{
    /**
     * Return the dining rooms.
     */
    #[OA\Get(path: '/api/v1/hospitality/rooms', operationId: 'listHospitalityRooms', description: 'Return the dining rooms.', summary: 'List rooms', security: [['bearerAuth' => []]], tags: ['Hospitality'], responses: [
        new OA\Response(response: 200, description: 'Rooms', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function rooms(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Room::class);

        return HospitalityResource::collection(Room::query()->orderBy('sort_order')->orderBy('name')->get());
    }

    /**
     * Create a dining room. Managers only.
     */
    #[OA\Post(path: '/api/v1/hospitality/rooms', operationId: 'storeHospitalityRoom', description: 'Create a dining room. Managers only.', summary: 'Create room', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRoomRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Room created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeRoom(HospitalityRequest $request): JsonResponse
    {
        $this->authorize('manage', Room::class);

        return (new HospitalityResource(Room::query()->create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Return the plan of a room: tables with position, size, and their open order.
     */
    #[OA\Get(path: '/api/v1/hospitality/rooms/{id}/plan', operationId: 'hospitalityRoomPlan', description: 'Return the plan of a room: tables with position, size, and their open order.', summary: 'Room plan', security: [['bearerAuth' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Plan', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityDataResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function plan(string $id, HospitalityService $service): JsonResponse
    {
        $room = Room::query()->findOrFail($id);
        $this->authorize('view', $room);

        return response()->json(['data' => $service->floorPlan($room)]);
    }

    /**
     * Place a table on the plan of a room. Managers only.
     */
    #[OA\Post(path: '/api/v1/hospitality/rooms/{id}/tables', operationId: 'storeHospitalityTable', description: 'Place a table on the plan of a room. Managers only.', summary: 'Add table', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityTableRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Table added', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeTable(HospitalityRequest $request, string $id): JsonResponse
    {
        $room = Room::query()->findOrFail($id);
        $this->authorize('manage', $room);
        abort_if(DiningTable::query()->where('room_id', $room->id)->where('name', $request->validated('name'))->exists(), Response::HTTP_UNPROCESSABLE_ENTITY, 'The room already has a table with this name.');

        return (new HospitalityResource(DiningTable::query()->create([...$request->validated(), 'room_id' => $room->id])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Move, resize or take a table out of service. Managers only.
     */
    #[OA\Put(path: '/api/v1/hospitality/tables/{id}', operationId: 'updateHospitalityTable', description: 'Move, resize or take a table out of service. Managers only.', summary: 'Change table', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityTableRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Table changed', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function updateTable(HospitalityRequest $request, string $id): HospitalityResource
    {
        $table = DiningTable::query()->findOrFail($id);
        $this->authorize('manage', $table);
        $table->update($request->validated());

        return new HospitalityResource($table);
    }
}
