<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Http\Requests\ServiceRecordRequest;
use Modules\Fleet\Http\Resources\ServiceRecordResource;
use Modules\Fleet\Models\FleetServiceRecord;
use Modules\Fleet\Services\ServiceRecordService;
use OpenApi\Attributes as OA;

/**
 * Service and maintenance records of the vehicles.
 */
final class ServiceRecordController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly ServiceRecordService $records) {}

    #[OA\Get(path: '/api/v1/fleet/services', operationId: 'listFleetServices', description: 'Service records, latest first. Filters: vehicle_id, service_type.', summary: 'List service records', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Service records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetServiceRecord'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetServiceRecord::class);
        $filters = $request->validate(['vehicle_id' => ['nullable', 'uuid'], 'service_type' => ['nullable', Rule::in(FleetServiceRecord::Types)]]);

        return ServiceRecordResource::collection(
            FleetServiceRecord::query()
                ->with('vehicle:id,license_plate')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['service_type']), static fn ($query) => $query->where('service_type', $filters['service_type']))
                ->orderByDesc('service_date')
                ->orderByDesc('odometer_km')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/services', operationId: 'storeFleetService', description: 'Record a service done on a vehicle. The odometer joins the vehicle timeline; when the record completes a scheduled service, that schedule moves forward by its intervals.', summary: 'Create a service record', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetServiceRecordRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Service record created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetServiceRecord')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(ServiceRecordRequest $request): JsonResponse
    {
        $this->authorize('create', FleetServiceRecord::class);

        return (new ServiceRecordResource($this->records->create($this->tenantId(), $request->validated())->load('vehicle:id,license_plate')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/services/{id}', operationId: 'showFleetService', description: 'Return a service record.', summary: 'Show a service record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Service record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetServiceRecord')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): ServiceRecordResource
    {
        $record = FleetServiceRecord::query()->with('vehicle:id,license_plate')->findOrFail($id);
        $this->authorize('view', $record);

        return new ServiceRecordResource($record);
    }

    #[OA\Put(path: '/api/v1/fleet/services/{id}', operationId: 'updateFleetService', description: 'Change a service record; the linked schedules are recomputed.', summary: 'Update a service record', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetServiceRecordRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Service record updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetServiceRecord')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(ServiceRecordRequest $request, string $id): ServiceRecordResource
    {
        $record = FleetServiceRecord::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new ServiceRecordResource($this->records->update($record, $request->validated())->load('vehicle:id,license_plate'));
    }

    #[OA\Delete(path: '/api/v1/fleet/services/{id}', operationId: 'deleteFleetService', description: 'Delete a service record (soft delete); the linked schedule is recomputed.', summary: 'Delete a service record', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Service record deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $record = FleetServiceRecord::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->records->delete($record);

        return response()->noContent();
    }
}
