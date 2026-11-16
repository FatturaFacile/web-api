<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Requests\DockAppointmentRequest;
use Modules\Wms\Http\Resources\DockAppointmentResource;
use Modules\Wms\Models\WmsDockAppointment;
use Modules\Wms\Services\DockAppointmentService;
use OpenApi\Attributes as OA;

/**
 * Dock appointments of the active company.
 */
final class DockAppointmentController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly DockAppointmentService $service) {}

    #[OA\Get(path: '/api/v1/wms/dock-appointments', operationId: 'listWmsDockAppointments', description: 'Dock appointments of the active company. Filters: warehouse_id, dock_door_id, direction, status, from, to, search.', summary: 'List dock appointments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'dock_door_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'direction', in: 'query', schema: new OA\Schema(type: 'string', enum: ['inbound', 'outbound'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['scheduled', 'checked_in', 'completed', 'cancelled', 'no_show'])), new OA\Parameter(name: 'from', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', description: 'Y-m-d', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Dock appointments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsDockAppointment'))])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsDockAppointment::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'dock_door_id' => ['nullable', 'uuid'], 'direction' => ['nullable', Rule::in(WmsDockAppointment::Directions)], 'status' => ['nullable', Rule::in(WmsDockAppointment::Statuses)], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'search' => ['nullable', 'string', 'max:100']]);

        return DockAppointmentResource::collection(
            WmsDockAppointment::query()
                ->with(['door:id,code,name,warehouse_id'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['dock_door_id']), static fn ($query) => $query->where('dock_door_id', $filters['dock_door_id']))
                ->when(isset($filters['direction']), static fn ($query) => $query->where('direction', $filters['direction']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['from']), static fn ($query) => $query->whereDate('starts_at', '>=', $filters['from']))
                ->when(isset($filters['to']), static fn ($query) => $query->whereDate('starts_at', '<=', $filters['to']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
                    $query->where(static fn ($inner) => $inner->where('carrier_name', 'like', $term)->orWhere('reference', 'like', $term));
                })
                ->orderBy('starts_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/wms/dock-appointments', operationId: 'storeWmsDockAppointment', description: 'Book a door. The door must be active and accept the direction, the end must follow the start and the slot must not overlap another scheduled or checked-in appointment of the door (422 on conflict).', summary: 'Create a dock appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsDockAppointmentRequest')), responses: [
        new OA\Response(response: 201, description: 'Dock appointment created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function store(DockAppointmentRequest $request): JsonResponse
    {
        $this->authorize('create', WmsDockAppointment::class);

        return (new DockAppointmentResource($this->service->create($this->tenantId(), $request->validated())->load(['door:id,code,name,warehouse_id'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/wms/dock-appointments/{id}', operationId: 'showWmsDockAppointment', description: 'Return a dock appointment.', summary: 'Show a dock appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Dock appointment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
    ])]
    public function show(string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->with(['door:id,code,name,warehouse_id'])->findOrFail($id);
        $this->authorize('view', $record);

        return new DockAppointmentResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/dock-appointments/{id}', operationId: 'updateWmsDockAppointment', description: 'Reschedule or change a scheduled appointment; the conflict check runs again.', summary: 'Update a dock appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsDockAppointmentRequest')), responses: [
        new OA\Response(response: 200, description: 'Dock appointment updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsDockAppointment')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function update(DockAppointmentRequest $request, string $id): DockAppointmentResource
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new DockAppointmentResource($this->service->update($record, $request->validated())->load(['door:id,code,name,warehouse_id']));
    }

    #[OA\Delete(path: '/api/v1/wms/dock-appointments/{id}', operationId: 'deleteWmsDockAppointment', description: 'Delete a dock appointment (soft delete).', summary: 'Delete a dock appointment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Dock appointment deleted'),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsDockAppointment::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
