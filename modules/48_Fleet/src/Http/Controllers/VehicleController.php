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
use Modules\Fleet\Http\Requests\VehicleRequest;
use Modules\Fleet\Http\Resources\VehicleResource;
use Modules\Fleet\Models\FleetVehicle;
use Modules\Fleet\Services\FleetDashboardService;
use Modules\Fleet\Services\VehicleService;
use OpenApi\Attributes as OA;

/**
 * Company vehicles: registration data, status, ownership and odometer.
 */
final class VehicleController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly VehicleService $vehicles, private readonly FleetDashboardService $dashboard) {}

    #[OA\Get(path: '/api/v1/fleet/vehicles', operationId: 'listFleetVehicles', description: 'Vehicles with their current driver. Filters: status, vehicle_type, fuel_type, ownership, search (plate, VIN, make, model).', summary: 'List vehicles', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Vehicles', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetVehicle'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetVehicle::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(FleetVehicle::Statuses)],
            'vehicle_type' => ['nullable', Rule::in(FleetVehicle::Types)],
            'fuel_type' => ['nullable', Rule::in(FleetVehicle::FuelTypes)],
            'ownership' => ['nullable', Rule::in(FleetVehicle::Ownerships)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return VehicleResource::collection(
            FleetVehicle::query()
                ->with('openAssignment.employee')
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['vehicle_type']), static fn ($query) => $query->where('vehicle_type', $filters['vehicle_type']))
                ->when(isset($filters['fuel_type']), static fn ($query) => $query->where('fuel_type', $filters['fuel_type']))
                ->when(isset($filters['ownership']), static fn ($query) => $query->where('ownership', $filters['ownership']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
                    $query->where(static fn ($inner) => $inner->where('license_plate', 'like', $term)->orWhere('vin', 'like', $term)->orWhere('make', 'like', $term)->orWhere('model', 'like', $term));
                })
                ->orderBy('license_plate')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/vehicles', operationId: 'storeFleetVehicle', description: 'Create a vehicle. The odometer given here is the starting point of the cost per km calculations.', summary: 'Create a vehicle', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetVehicleRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Vehicle created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetVehicle')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(VehicleRequest $request): JsonResponse
    {
        $this->authorize('create', FleetVehicle::class);

        return (new VehicleResource($this->vehicles->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/vehicles/{id}', operationId: 'showFleetVehicle', description: 'Return a vehicle with its current driver.', summary: 'Show a vehicle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetVehicle')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): VehicleResource
    {
        $vehicle = FleetVehicle::query()->with('openAssignment.employee')->findOrFail($id);
        $this->authorize('view', $vehicle);

        return new VehicleResource($vehicle);
    }

    #[OA\Put(path: '/api/v1/fleet/vehicles/{id}', operationId: 'updateFleetVehicle', description: 'Change a vehicle. The odometer can only go up; a vehicle with an open driver assignment cannot be sold or disposed of.', summary: 'Update a vehicle', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetVehicleRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Vehicle updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetVehicle')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(VehicleRequest $request, string $id): VehicleResource
    {
        $vehicle = FleetVehicle::query()->findOrFail($id);
        $this->authorize('update', $vehicle);

        return new VehicleResource($this->vehicles->update($vehicle, $request->validated())->load('openAssignment.employee'));
    }

    #[OA\Delete(path: '/api/v1/fleet/vehicles/{id}', operationId: 'deleteFleetVehicle', description: 'Delete a vehicle (soft delete).', summary: 'Delete a vehicle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Vehicle deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $vehicle = FleetVehicle::query()->findOrFail($id);
        $this->authorize('delete', $vehicle);
        $vehicle->delete();

        return response()->noContent();
    }

    #[OA\Get(path: '/api/v1/fleet/vehicles/{id}/costs', operationId: 'showFleetVehicleCosts', description: 'Running cost (fuel, services, deadlines, incidents), total cost of ownership and cost per km of a vehicle.', summary: 'Costs of a vehicle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Costs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetVehicleCosts')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function costs(string $id): JsonResponse
    {
        $vehicle = FleetVehicle::query()->findOrFail($id);
        $this->authorize('view', $vehicle);

        return response()->json(['data' => $this->dashboard->costsOf($vehicle)]);
    }
}
