<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Http\Requests\AssignmentRequest;
use Modules\Fleet\Http\Requests\EndAssignmentRequest;
use Modules\Fleet\Http\Resources\AssignmentResource;
use Modules\Fleet\Models\FleetVehicleAssignment;
use Modules\Fleet\Services\AssignmentService;
use OpenApi\Attributes as OA;

/**
 * Vehicle to driver assignments with the odometer at handover and at return.
 */
final class AssignmentController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly AssignmentService $assignments) {}

    #[OA\Get(path: '/api/v1/fleet/assignments', operationId: 'listFleetAssignments', description: 'Driver assignments, latest first. Filters: vehicle_id, employee_id, open (1 for the assignments that have not ended).', summary: 'List driver assignments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Assignments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetAssignment'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetVehicleAssignment::class);
        $filters = $request->validate(['vehicle_id' => ['nullable', 'uuid'], 'employee_id' => ['nullable', 'uuid'], 'open' => ['nullable', 'boolean']]);

        return AssignmentResource::collection(
            FleetVehicleAssignment::query()
                ->with(['vehicle:id,license_plate', 'employee'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['employee_id']), static fn ($query) => $query->where('employee_id', $filters['employee_id']))
                ->when(array_key_exists('open', $filters) && $filters['open'] !== null, static fn ($query) => $filters['open'] ? $query->whereNull('ends_on') : $query->whereNotNull('ends_on'))
                ->orderByDesc('starts_on')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/assignments', operationId: 'storeFleetAssignment', description: 'Hand a vehicle to a driver (an employee, or an external driver by name). Periods of one vehicle cannot overlap; the start odometer defaults to the odometer of the vehicle and can never be lower than the earlier readings.', summary: 'Assign a vehicle to a driver', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetAssignmentRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Assignment created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetAssignment')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(AssignmentRequest $request): JsonResponse
    {
        $this->authorize('create', FleetVehicleAssignment::class);

        return (new AssignmentResource($this->assignments->create($this->tenantId(), $request->validated())->load(['vehicle:id,license_plate', 'employee'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/assignments/{id}', operationId: 'showFleetAssignment', description: 'Return a driver assignment.', summary: 'Show a driver assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Assignment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetAssignment')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): AssignmentResource
    {
        $assignment = FleetVehicleAssignment::query()->with(['vehicle:id,license_plate', 'employee'])->findOrFail($id);
        $this->authorize('view', $assignment);

        return new AssignmentResource($assignment);
    }

    #[OA\Put(path: '/api/v1/fleet/assignments/{id}', operationId: 'updateFleetAssignment', description: 'Change an assignment. The same overlap and odometer rules apply.', summary: 'Update a driver assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetAssignmentRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Assignment updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetAssignment')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(AssignmentRequest $request, string $id): AssignmentResource
    {
        $assignment = FleetVehicleAssignment::query()->findOrFail($id);
        $this->authorize('update', $assignment);

        return new AssignmentResource($this->assignments->update($assignment, $request->validated())->load(['vehicle:id,license_plate', 'employee']));
    }

    #[OA\Post(path: '/api/v1/fleet/assignments/{id}/end', operationId: 'endFleetAssignment', description: 'Record the return of the vehicle: the date and the odometer at return, which cannot be lower than the odometer at handover.', summary: 'End a driver assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetAssignmentEndRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Assignment ended', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetAssignment')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function end(EndAssignmentRequest $request, string $id): AssignmentResource
    {
        $assignment = FleetVehicleAssignment::query()->findOrFail($id);
        $this->authorize('update', $assignment);

        return new AssignmentResource($this->assignments->end($assignment, $request->validated())->load(['vehicle:id,license_plate', 'employee']));
    }

    #[OA\Delete(path: '/api/v1/fleet/assignments/{id}', operationId: 'deleteFleetAssignment', description: 'Delete a driver assignment (soft delete).', summary: 'Delete a driver assignment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Assignment deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $assignment = FleetVehicleAssignment::query()->findOrFail($id);
        $this->authorize('delete', $assignment);
        $assignment->delete();

        return response()->noContent();
    }
}
