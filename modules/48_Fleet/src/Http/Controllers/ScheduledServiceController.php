<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Http\Requests\ScheduledServiceRequest;
use Modules\Fleet\Http\Resources\ScheduledServiceResource;
use Modules\Fleet\Models\FleetScheduledService;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Services\ScheduledServiceService;
use OpenApi\Attributes as OA;

/**
 * Scheduled services due by date or by kilometres, with overdue detection.
 */
final class ScheduledServiceController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly ScheduledServiceService $schedules) {}

    #[OA\Get(path: '/api/v1/fleet/scheduled-services', operationId: 'listFleetScheduledServices', description: 'Scheduled services, the earliest due first. Filters: vehicle_id, overdue (1 for the active services whose due date has passed or whose due kilometres are reached), active.', summary: 'List scheduled services', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Scheduled services', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetScheduledService'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetScheduledService::class);
        $filters = $request->validate(['vehicle_id' => ['nullable', 'uuid'], 'overdue' => ['nullable', 'boolean'], 'active' => ['nullable', 'boolean']]);
        $today = Carbon::today()->toDateString();

        return ScheduledServiceResource::collection(
            FleetScheduledService::query()
                ->with('vehicle')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(array_key_exists('active', $filters) && $filters['active'] !== null, static fn ($query) => $query->where('is_active', (bool) $filters['active']))
                ->when(! empty($filters['overdue']), static function (Builder $query) use ($today): void {
                    $query->where('is_active', true)->where(static function (Builder $due) use ($today): void {
                        $due->whereDate('next_due_on', '<', $today)
                            ->orWhere(static fn (Builder $byKm) => $byKm->whereNotNull('next_due_km')->whereHas('vehicle', static fn ($vehicle) => $vehicle->whereColumn('fleet_vehicles.odometer_km', '>=', 'fleet_scheduled_services.next_due_km')));
                    });
                })
                ->orderBy('next_due_on')
                ->orderBy('name')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/scheduled-services', operationId: 'storeFleetScheduledService', description: 'Schedule a recurring service for a vehicle. The due date and kilometres left out are computed from the intervals, counting from the last service or from today and the current odometer.', summary: 'Create a scheduled service', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetScheduledServiceRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Scheduled service created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetScheduledService')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(ScheduledServiceRequest $request): JsonResponse
    {
        $this->authorize('create', FleetScheduledService::class);
        $data = $request->validated();
        $vehicle = FleetVehicle::query()->findOrFail($data['vehicle_id']);

        return (new ScheduledServiceResource($this->schedules->create($this->tenantId(), $vehicle, $data)->load('vehicle')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/scheduled-services/{id}', operationId: 'showFleetScheduledService', description: 'Return a scheduled service.', summary: 'Show a scheduled service', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Scheduled service', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetScheduledService')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): ScheduledServiceResource
    {
        $schedule = FleetScheduledService::query()->with('vehicle')->findOrFail($id);
        $this->authorize('view', $schedule);

        return new ScheduledServiceResource($schedule);
    }

    #[OA\Put(path: '/api/v1/fleet/scheduled-services/{id}', operationId: 'updateFleetScheduledService', description: 'Change a scheduled service.', summary: 'Update a scheduled service', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetScheduledServiceRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Scheduled service updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetScheduledService')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(ScheduledServiceRequest $request, string $id): ScheduledServiceResource
    {
        $schedule = FleetScheduledService::query()->findOrFail($id);
        $this->authorize('update', $schedule);

        return new ScheduledServiceResource($this->schedules->update($schedule, $request->validated())->load('vehicle'));
    }

    #[OA\Delete(path: '/api/v1/fleet/scheduled-services/{id}', operationId: 'deleteFleetScheduledService', description: 'Delete a scheduled service (soft delete). Its service records are kept.', summary: 'Delete a scheduled service', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Scheduled service deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $schedule = FleetScheduledService::query()->findOrFail($id);
        $this->authorize('delete', $schedule);
        $schedule->delete();

        return response()->noContent();
    }
}
