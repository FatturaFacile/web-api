<?php

declare(strict_types=1);

namespace Modules\Workshop\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Workshop\Http\Requests\SchedulingRequest;
use Modules\Workshop\Http\Resources\WorkshopResource;
use Modules\Workshop\Models\WorkshopBay;
use Modules\Workshop\Models\WorkshopJobSlot;
use Modules\Workshop\Services\SchedulingService;
use OpenApi\Attributes as OA;

/**
 * Workshop schedule: service bays and the planned slots of technicians on folders, with conflict detection.
 */
final class SchedulingController extends Controller
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    #[OA\Get(path: '/api/v1/workshop/bays', operationId: 'listWorkshopBays', description: 'Service bays by code.', summary: 'List service bays', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workshop'], responses: [
        new OA\Response(response: 200, description: 'Bays', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
    ])]
    public function bays(): JsonResponse
    {
        $this->authorize('viewAny', WorkshopBay::class);

        return response()->json(['data' => WorkshopResource::collection(WorkshopBay::query()->orderBy('code')->get())->resolve()]);
    }

    #[OA\Post(path: '/api/v1/workshop/bays', operationId: 'storeWorkshopBay', description: 'Defines a service bay. The code is unique in the company.', summary: 'Create a service bay', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopBayRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Bay created', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function storeBay(SchedulingRequest $request): JsonResponse
    {
        $this->authorize('create', WorkshopBay::class);
        $this->assertBayCodeFree((string) $request->validated('code'), null);

        return (new WorkshopResource(WorkshopBay::create($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/workshop/bays/{id}', operationId: 'updateWorkshopBay', description: 'Changes a service bay; an inactive bay cannot be booked.', summary: 'Update a service bay', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopBayRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Bay updated', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function updateBay(SchedulingRequest $request, string $id): WorkshopResource
    {
        $bay = WorkshopBay::query()->findOrFail($id);
        $this->authorize('update', $bay);
        $this->assertBayCodeFree((string) ($request->validated('code') ?? $bay->code), $bay->id);
        $bay->update($request->validated());

        return new WorkshopResource($bay->refresh());
    }

    #[OA\Get(path: '/api/v1/workshop/schedule', operationId: 'listWorkshopSchedule', description: 'The slots that overlap the window [from, to), by start time, with their folder and bay. Filters: technician_user_id, bay_id, folder_id. Cancelled slots are included so the board can show them.', summary: 'Schedule of a time window', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workshop'], parameters: [
        new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
        new OA\Parameter(name: 'technician_user_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'bay_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'folder_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
    ], responses: [
        new OA\Response(response: 200, description: 'Slots', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordCollection')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function index(SchedulingRequest $request): JsonResponse
    {
        $this->authorize('viewAny', WorkshopJobSlot::class);
        $validated = $request->validated();
        $from = CarbonImmutable::parse($validated['from'])->utc()->format('Y-m-d H:i:s');
        $to = CarbonImmutable::parse($validated['to'])->utc()->format('Y-m-d H:i:s');
        if (CarbonImmutable::parse($validated['to'])->diffInDays(CarbonImmutable::parse($validated['from']), true) > 62) {
            throw ValidationException::withMessages(['to' => ['The window is limited to 62 days.']]);
        }

        $slots = WorkshopJobSlot::query()->with(['folder', 'bay'])
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when(isset($validated['technician_user_id']), static fn ($query) => $query->where('technician_user_id', $validated['technician_user_id']))
            ->when(isset($validated['bay_id']), static fn ($query) => $query->where('bay_id', $validated['bay_id']))
            ->when(isset($validated['folder_id']), static fn ($query) => $query->where('folder_id', $validated['folder_id']))
            ->orderBy('starts_at')->get();

        return response()->json(['data' => WorkshopResource::collection($slots)->resolve()]);
    }

    #[OA\Post(path: '/api/v1/workshop/schedule', operationId: 'storeWorkshopSlot', description: 'Plans a window of work on an open or in-progress folder for a technician and/or a bay. Refused when the technician or the bay already holds a planned or in-progress slot that overlaps the window.', summary: 'Plan a slot', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopSlotRequest')), tags: ['Workshop'], responses: [
        new OA\Response(response: 201, description: 'Slot planned', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function store(SchedulingRequest $request): JsonResponse
    {
        $this->authorize('create', WorkshopJobSlot::class);

        return (new WorkshopResource($this->scheduling->plan($request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/workshop/schedule/{id}', operationId: 'updateWorkshopSlot', description: 'Moves a planned slot to another window, technician or bay, with the same conflict check.', summary: 'Reschedule a slot', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkshopSlotRequest')), tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Slot moved', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function update(SchedulingRequest $request, string $id): WorkshopResource
    {
        return new WorkshopResource($this->scheduling->reschedule($this->slot($id), $request->validated()));
    }

    #[OA\Post(path: '/api/v1/workshop/schedule/{id}/start', operationId: 'startWorkshopSlot', description: 'Starts a planned slot.', summary: 'Start a slot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Slot in progress', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function start(string $id): WorkshopResource
    {
        return new WorkshopResource($this->scheduling->transition($this->slot($id), 'in_progress'));
    }

    #[OA\Post(path: '/api/v1/workshop/schedule/{id}/complete', operationId: 'completeWorkshopSlot', description: 'Completes a slot in progress and frees the technician and the bay.', summary: 'Complete a slot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Slot done', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function complete(string $id): WorkshopResource
    {
        return new WorkshopResource($this->scheduling->transition($this->slot($id), 'done'));
    }

    #[OA\Post(path: '/api/v1/workshop/schedule/{id}/cancel', operationId: 'cancelWorkshopSlot', description: 'Cancels a planned or in-progress slot and frees the technician and the bay.', summary: 'Cancel a slot', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Workshop'], parameters: [new OA\Parameter(ref: '#/components/parameters/WorkshopId')], responses: [
        new OA\Response(response: 200, description: 'Slot cancelled', content: new OA\JsonContent(ref: '#/components/schemas/WorkshopRecordResponse')),
        new OA\Response(ref: '#/components/responses/WorkshopUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WorkshopForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WorkshopNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WorkshopUnprocessable', response: 422),
    ])]
    public function cancel(string $id): WorkshopResource
    {
        return new WorkshopResource($this->scheduling->transition($this->slot($id), 'cancelled'));
    }

    private function slot(string $id): WorkshopJobSlot
    {
        $slot = WorkshopJobSlot::query()->findOrFail($id);
        $this->authorize('update', $slot);

        return $slot;
    }

    private function assertBayCodeFree(string $code, ?string $ignoreId): void
    {
        $taken = WorkshopBay::query()->where('code', $code)->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => ['A bay with this code exists.']]);
        }
    }
}
