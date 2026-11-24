<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Http\Requests\DeadlineRequest;
use Modules\Fleet\Http\Requests\RenewDeadlineRequest;
use Modules\Fleet\Http\Resources\DeadlineResource;
use Modules\Fleet\Models\FleetDeadline;
use Modules\Fleet\Services\DeadlineService;
use OpenApi\Attributes as OA;

/**
 * Compliance deadlines (road tax, insurance, inspection) with expiry alerts.
 */
final class DeadlineController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly DeadlineService $deadlines) {}

    #[OA\Get(path: '/api/v1/fleet/deadlines', operationId: 'listFleetDeadlines', description: 'Compliance deadlines, the earliest expiry first. Filters: vehicle_id, deadline_type, status, expiring (days: open deadlines that expire within that many days, expired ones included), expired (1 for the open deadlines already expired).', summary: 'List compliance deadlines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Deadlines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetDeadline'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetDeadline::class);
        $filters = $request->validate([
            'vehicle_id' => ['nullable', 'uuid'],
            'deadline_type' => ['nullable', Rule::in(FleetDeadline::Types)],
            'status' => ['nullable', Rule::in(FleetDeadline::Statuses)],
            'expiring' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'expired' => ['nullable', 'boolean'],
        ]);
        $today = Carbon::today();

        return DeadlineResource::collection(
            FleetDeadline::query()
                ->with('vehicle:id,license_plate')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['deadline_type']), static fn ($query) => $query->where('deadline_type', $filters['deadline_type']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['expiring']), static fn ($query) => $query->where('status', FleetDeadline::Open)->whereDate('expires_on', '<=', $today->copy()->addDays((int) $filters['expiring'])->toDateString()))
                ->when(! empty($filters['expired']), static fn ($query) => $query->where('status', FleetDeadline::Open)->whereDate('expires_on', '<', $today->toDateString()))
                ->orderBy('expires_on')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/deadlines', operationId: 'storeFleetDeadline', description: 'Register a compliance deadline of a vehicle (road_tax, insurance, inspection, other).', summary: 'Create a compliance deadline', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetDeadlineRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Deadline created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetDeadline')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(DeadlineRequest $request): JsonResponse
    {
        $this->authorize('create', FleetDeadline::class);

        return (new DeadlineResource($this->deadlines->create($this->tenantId(), $request->validated())->load('vehicle:id,license_plate')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/deadlines/{id}', operationId: 'showFleetDeadline', description: 'Return a compliance deadline.', summary: 'Show a compliance deadline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Deadline', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetDeadline')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): DeadlineResource
    {
        $deadline = FleetDeadline::query()->with('vehicle:id,license_plate')->findOrFail($id);
        $this->authorize('view', $deadline);

        return new DeadlineResource($deadline);
    }

    #[OA\Put(path: '/api/v1/fleet/deadlines/{id}', operationId: 'updateFleetDeadline', description: 'Change a compliance deadline.', summary: 'Update a compliance deadline', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetDeadlineRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Deadline updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetDeadline')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(DeadlineRequest $request, string $id): DeadlineResource
    {
        $deadline = FleetDeadline::query()->findOrFail($id);
        $this->authorize('update', $deadline);

        return new DeadlineResource($this->deadlines->update($deadline, $request->validated())->load('vehicle:id,license_plate'));
    }

    #[OA\Post(path: '/api/v1/fleet/deadlines/{id}/renew', operationId: 'renewFleetDeadline', description: 'Close an open deadline as renewed and open the next one with the new expiry date (after the current one). Cost, provider and reference carry over unless given. Returns the new deadline.', summary: 'Renew a compliance deadline', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetDeadlineRenewRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Deadline renewed: the new open deadline', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetDeadline')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function renew(RenewDeadlineRequest $request, string $id): JsonResponse
    {
        $deadline = FleetDeadline::query()->findOrFail($id);
        $this->authorize('update', $deadline);

        return (new DeadlineResource($this->deadlines->renew($deadline, $request->validated())->load('vehicle:id,license_plate')))->response()->setStatusCode(Response::HTTP_OK);
    }

    #[OA\Delete(path: '/api/v1/fleet/deadlines/{id}', operationId: 'deleteFleetDeadline', description: 'Delete a compliance deadline (soft delete).', summary: 'Delete a compliance deadline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Deadline deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $deadline = FleetDeadline::query()->findOrFail($id);
        $this->authorize('delete', $deadline);
        $deadline->delete();

        return response()->noContent();
    }
}
