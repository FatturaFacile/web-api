<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hospitality\Http\Requests\GroupBookingRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\GroupBlock;
use Modules\Hospitality\Models\Stay;
use Modules\Hospitality\Services\GroupBookingService;
use OpenApi\Attributes as OA;

/**
 * Group bookings: blocks of rooms with pickup and release dates, and the stays picked up from them.
 */
final class GroupBlockController extends Controller
{
    public function __construct(private readonly GroupBookingService $groups) {}

    #[OA\Get(path: '/api/v1/hospitality/group-blocks', operationId: 'listHospitalityGroupBlocks', description: 'Group blocks by check-in date. Filters: status.', summary: 'List group blocks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'released', 'cancelled'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Group blocks', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GroupBlock::class);

        return HospitalityResource::collection(
            GroupBlock::query()->with('rooms')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->orderBy('check_in_date')->orderBy('number')->paginate(RequestedPerPage::resolve(25))
        );
    }

    #[OA\Post(path: '/api/v1/hospitality/group-blocks', operationId: 'storeHospitalityGroupBlock', description: 'Holds rooms for a group: per room type the rooms blocked for the whole interval, refused when the type does not have them free. The unpicked rooms go back to the general availability on the release date, which cannot be after the check-in date.', summary: 'Block rooms for a group', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityGroupBlockRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Block created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function store(GroupBookingRequest $request): JsonResponse
    {
        $this->authorize('create', GroupBlock::class);

        return $this->respond($this->groups->create($request->validated()), 201);
    }

    #[OA\Get(path: '/api/v1/hospitality/group-blocks/{id}', operationId: 'showHospitalityGroupBlock', description: 'A group block with its rooms, the pickup per room type (blocked, picked up, remaining) and the stays picked up.', summary: 'Show a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Group block', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->respond($this->block($id, 'view'));
    }

    #[OA\Put(path: '/api/v1/hospitality/group-blocks/{id}', operationId: 'updateHospitalityGroupBlock', description: 'Changes an open block: name, organizer, customer, release date, notes and, with rooms, the full list of room types held (never fewer rooms than already picked up). The dates of the block do not change.', summary: 'Change a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityGroupBlockRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Block changed', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function update(GroupBookingRequest $request, string $id): JsonResponse
    {
        return $this->respond($this->groups->update($this->block($id, 'update'), $request->validated()));
    }

    #[OA\Post(path: '/api/v1/hospitality/group-blocks/{id}/dates', operationId: 'changeHospitalityGroupBlockDates', description: 'Moves an open block to new dates. Refused when stays already picked up fall outside the new dates or a room type no longer has the unpicked rooms free. The release date is kept unless one is sent, and cannot be after the new check-in date.', summary: 'Change the dates of a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityGroupBlockDatesRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Block moved', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function changeDates(GroupBookingRequest $request, string $id): JsonResponse
    {
        return $this->respond($this->groups->changeDates($this->block($id, 'update'), $request->validated()));
    }

    #[OA\Post(path: '/api/v1/hospitality/group-blocks/{id}/release', operationId: 'releaseHospitalityGroupBlock', description: 'Gives the rooms nobody picked up back to the general availability now. Stays already picked up are untouched.', summary: 'Release a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Block released', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function release(string $id): JsonResponse
    {
        return $this->respond($this->groups->release($this->block($id, 'update')));
    }

    #[OA\Post(path: '/api/v1/hospitality/group-blocks/{id}/cancel', operationId: 'cancelHospitalityGroupBlock', description: 'Cancels the block and frees every room it held. The stays already picked up stay as ordinary bookings.', summary: 'Cancel a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Block cancelled', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): JsonResponse
    {
        $block = $this->block($id, 'manage');

        return $this->respond($this->groups->cancel($block));
    }

    #[OA\Post(path: '/api/v1/hospitality/group-blocks/{id}/stays', operationId: 'storeHospitalityGroupBlockStay', description: 'Books a stay out of the block (pickup), by default for the dates of the block. Refused when the block is released, expired or cancelled, the room type has no rooms left to pick up, or the dates fall outside the block. The negotiated rate of the block applies when it has one.', summary: 'Pick up a room from a group block', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityGroupStayRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 201, description: 'Stay booked', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function storeStay(GroupBookingRequest $request, string $id): JsonResponse
    {
        $block = $this->block($id, 'update');
        $this->authorize('create', Stay::class);

        return (new HospitalityResource($this->groups->bookStay($block, $request->validated())->load('roomType')))->response()->setStatusCode(201);
    }

    private function block(string $id, string $ability): GroupBlock
    {
        $block = GroupBlock::query()->findOrFail($id);
        $this->authorize($ability, $block);

        return $block;
    }

    private function respond(GroupBlock $block, int $status = 200): JsonResponse
    {
        $block->load(['rooms', 'stays']);

        return response()->json(['data' => [
            ...$block->toArray(),
            'pickup' => $this->groups->pickup($block),
            'is_expired' => $this->groups->isExpired($block),
        ]], $status);
    }
}
