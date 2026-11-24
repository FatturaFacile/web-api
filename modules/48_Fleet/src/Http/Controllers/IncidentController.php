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
use Modules\Fleet\Http\Requests\IncidentRequest;
use Modules\Fleet\Http\Requests\PayIncidentRequest;
use Modules\Fleet\Http\Resources\IncidentResource;
use Modules\Fleet\Models\FleetIncident;
use Modules\Fleet\Services\IncidentService;
use OpenApi\Attributes as OA;

/**
 * Traffic fines, accidents and damages with driver attribution and payment status.
 */
final class IncidentController extends Controller
{
    use InteractsWithFleet;

    public function __construct(private readonly IncidentService $incidents) {}

    #[OA\Get(path: '/api/v1/fleet/incidents', operationId: 'listFleetIncidents', description: 'Incidents and fines, latest first. Filters: vehicle_id, employee_id, kind, payment_status.', summary: 'List incidents and fines', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], responses: [
        new OA\Response(response: 200, description: 'Incidents', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FleetIncident'))])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', FleetIncident::class);
        $filters = $request->validate([
            'vehicle_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'kind' => ['nullable', Rule::in(FleetIncident::Kinds)],
            'payment_status' => ['nullable', Rule::in(FleetIncident::PaymentStatuses)],
        ]);

        return IncidentResource::collection(
            FleetIncident::query()
                ->with(['vehicle:id,license_plate', 'employee'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['vehicle_id']), static fn ($query) => $query->where('vehicle_id', $filters['vehicle_id']))
                ->when(isset($filters['employee_id']), static fn ($query) => $query->where('employee_id', $filters['employee_id']))
                ->when(isset($filters['kind']), static fn ($query) => $query->where('kind', $filters['kind']))
                ->when(isset($filters['payment_status']), static fn ($query) => $query->where('payment_status', $filters['payment_status']))
                ->orderByDesc('occurred_on')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/fleet/incidents', operationId: 'storeFleetIncident', description: 'Record a traffic fine, accident or damage. When no employee is given the driver is the one holding the vehicle on the day of the incident.', summary: 'Create an incident or fine', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetIncidentRequest')), tags: ['Fleet'], responses: [
        new OA\Response(response: 201, description: 'Incident created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetIncident')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function store(IncidentRequest $request): JsonResponse
    {
        $this->authorize('create', FleetIncident::class);

        return (new IncidentResource($this->incidents->create($this->tenantId(), $request->validated())->load(['vehicle:id,license_plate', 'employee'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/fleet/incidents/{id}', operationId: 'showFleetIncident', description: 'Return an incident or fine.', summary: 'Show an incident or fine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Incident', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetIncident')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function show(string $id): IncidentResource
    {
        $incident = FleetIncident::query()->with(['vehicle:id,license_plate', 'employee'])->findOrFail($id);
        $this->authorize('view', $incident);

        return new IncidentResource($incident);
    }

    #[OA\Put(path: '/api/v1/fleet/incidents/{id}', operationId: 'updateFleetIncident', description: 'Change an incident or fine, its driver or its payment status. A paid incident carries its payment date; any other status clears it.', summary: 'Update an incident or fine', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/FleetIncidentRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Incident updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetIncident')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function update(IncidentRequest $request, string $id): IncidentResource
    {
        $incident = FleetIncident::query()->findOrFail($id);
        $this->authorize('update', $incident);

        return new IncidentResource($this->incidents->update($incident, $request->validated())->load(['vehicle:id,license_plate', 'employee']));
    }

    #[OA\Post(path: '/api/v1/fleet/incidents/{id}/pay', operationId: 'payFleetIncident', description: 'Mark an incident or fine as paid on the given date (today when left out).', summary: 'Mark an incident as paid', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/FleetIncidentPayRequest')), tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 200, description: 'Incident paid', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/FleetIncident')])),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/FleetUnprocessable', response: 422),
    ])]
    public function pay(PayIncidentRequest $request, string $id): IncidentResource
    {
        $incident = FleetIncident::query()->findOrFail($id);
        $this->authorize('update', $incident);

        return new IncidentResource($this->incidents->pay($incident, $request->validated('paid_on'))->load(['vehicle:id,license_plate', 'employee']));
    }

    #[OA\Delete(path: '/api/v1/fleet/incidents/{id}', operationId: 'deleteFleetIncident', description: 'Delete an incident or fine (soft delete).', summary: 'Delete an incident or fine', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Fleet'], parameters: [new OA\Parameter(ref: '#/components/parameters/FleetId')], responses: [
        new OA\Response(response: 204, description: 'Incident deleted'),
        new OA\Response(ref: '#/components/responses/FleetUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/FleetForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/FleetNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $incident = FleetIncident::query()->findOrFail($id);
        $this->authorize('delete', $incident);
        $incident->delete();

        return response()->noContent();
    }
}
