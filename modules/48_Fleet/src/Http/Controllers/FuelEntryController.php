<?php

declare(strict_types=1);

namespace Modules\Fleet\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Fleet\Http\Controllers\Concerns\InteractsWithFleet;
use Modules\Fleet\Http\Requests\FuelEntryRequest;
use Modules\Fleet\Http\Resources\FuelEntryResource;
use Modules\Fleet\Models\FleetFuelEntry;
use Modules\Fleet\Services\FuelEntryService;
use OpenApi\Attributes as OA;

/**
 * Fuel and charging entries with consumption per 100 km and cost per km.
 */
final class FuelEntryController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly FuelEntryService $entries) {}

    #[OA\Get(path: '/api/v1/fleet/fuel-entries', operationId: 'listFleetFuelEntries', description: 'Fuel and charging entries, latest first. Filters: vehicle_id, from, to (Y-m-d).', summary: 'List fuel entries', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Fuel entries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetFuelEntry'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetFuelEntry::class);
        $filters = $request->validate(['vehicle_id' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);

        return FuelEntryResource::collection(
            FleetFuelEntry::query()
                ->with(['vehicle:id,license_plate', 'employee'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('entry_date', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('entry_date', '<=', $filters['to']))
                ->orderByDesc('entry_date')
                ->orderByDesc('odometer_km')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/fuel-entries', operationId: 'storeFleetFuelEntry', description: 'Record a refuel or charging session (liter, kwh or kg). Give the unit price, the total cost or both. The odometer joins the vehicle timeline and never decreases it; consumption and cost per km are computed between full tanks.', summary: 'Create a fuel entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetFuelEntryRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Fuel entry created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetFuelEntry')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(FuelEntryRequest $request): JsonResponse
    {
        $this->authorize('create', FleetFuelEntry::class);

        return (new FuelEntryResource($this->entries->create($this->tenantId(), $request->validated())->load(['vehicle:id,license_plate', 'employee'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/fuel-entries/{id}', operationId: 'showFleetFuelEntry', description: 'Return a fuel entry.', summary: 'Show a fuel entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Fuel entry', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetFuelEntry')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): FuelEntryResource
    {
        $entry = FleetFuelEntry::query()->with(['vehicle:id,license_plate', 'employee'])->findOrFail($id);
        $this->authorize('view', $entry);

        return new FuelEntryResource($entry);
    }

    #[OA\Put(path: '/api/v1/fleet/fuel-entries/{id}', operationId: 'updateFleetFuelEntry', description: 'Change a fuel entry; consumption and cost per km of the vehicle are recomputed.', summary: 'Update a fuel entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetFuelEntryRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Fuel entry updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetFuelEntry')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(FuelEntryRequest $request, string $id): FuelEntryResource
    {
        $entry = FleetFuelEntry::query()->findOrFail($id);
        $this->authorize('update', $entry);

        return new FuelEntryResource($this->entries->update($entry, $request->validated())->load(['vehicle:id,license_plate', 'employee']));
    }

    #[OA\Delete(path: '/api/v1/fleet/fuel-entries/{id}', operationId: 'deleteFleetFuelEntry', description: 'Delete a fuel entry (soft delete); consumption and cost per km of the vehicle are recomputed. The odometer of the vehicle does not go back.', summary: 'Delete a fuel entry', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Fuel entry deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $entry = FleetFuelEntry::query()->findOrFail($id);
        $this->authorize('delete', $entry);
        $this->entries->delete($entry);

        return response()->noContent();
    }
}
