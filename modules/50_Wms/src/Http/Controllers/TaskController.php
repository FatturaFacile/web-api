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
use Modules\Wms\Http\Requests\TaskRequest;
use Modules\Wms\Http\Resources\TaskResource;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Services\TaskService;
use OpenApi\Attributes as OA;

/**
 * Tasks of the active company.
 */
final class TaskController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly TaskService $service) {}

    #[OA\Get(path: '/api/v1/wms/tasks', operationId: 'listWmsTasks', description: 'Tasks of the active company. Filters: warehouse_id, task_type, status, assigned_to, wave_id, search.', summary: 'List tasks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'warehouse_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'task_type', in: 'query', schema: new OA\Schema(type: 'string', enum: ['putaway', 'pick', 'replenish', 'count', 'move'])), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['open', 'assigned', 'in_progress', 'completed', 'short_completed', 'skipped', 'cancelled'])), new OA\Parameter(name: 'assigned_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'wave_id', in: 'query', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'search', in: 'query', schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Tasks', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsTask'))])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WmsTask::class);
        $filters = $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'task_type' => ['nullable', Rule::in(WmsTask::TaskTypes)], 'status' => ['nullable', Rule::in(WmsTask::Statuses)], 'assigned_to' => ['nullable', 'uuid'], 'wave_id' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:100']]);

        return TaskResource::collection(
            WmsTask::query()
                ->with(['warehouse:id,code,name', 'stockItem:id,sku,name', 'sourceBin:id,code', 'destinationBin:id,code'])
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['warehouse_id']), static fn ($query) => $query->where('warehouse_id', $filters['warehouse_id']))
                ->when(isset($filters['task_type']), static fn ($query) => $query->where('task_type', $filters['task_type']))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['assigned_to']), static fn ($query) => $query->where('assigned_to', $filters['assigned_to']))
                ->when(isset($filters['wave_id']), static fn ($query) => $query->where('wave_id', $filters['wave_id']))
                ->when(isset($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
                    $query->where(static fn ($inner) => $inner->where('reference', 'like', $term)->orWhere('notes', 'like', $term));
                })
                ->orderByDesc('created_at')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/wms/tasks', operationId: 'storeWmsTask', description: 'Create an open task. Bins and the stock item must belong to the warehouse of the task and the quantity must be positive; a task assigned on creation starts as assigned.', summary: 'Create a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsTaskRequest')), responses: [
        new OA\Response(response: 201, description: 'Task created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function store(TaskRequest $request): JsonResponse
    {
        $this->authorize('create', WmsTask::class);

        return (new TaskResource($this->service->create($this->tenantId(), $request->validated())->load(['warehouse:id,code,name', 'stockItem:id,sku,name', 'sourceBin:id,code', 'destinationBin:id,code'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/wms/tasks/{id}', operationId: 'showWmsTask', description: 'Return a task.', summary: 'Show a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Task', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
    ])]
    public function show(string $id): TaskResource
    {
        $record = WmsTask::query()->with(['warehouse:id,code,name', 'stockItem:id,sku,name', 'sourceBin:id,code', 'destinationBin:id,code'])->findOrFail($id);
        $this->authorize('view', $record);

        return new TaskResource($record);
    }

    #[OA\Put(path: '/api/v1/wms/tasks/{id}', operationId: 'updateWmsTask', description: 'Change a task that is still open or assigned.', summary: 'Update a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WmsTaskRequest')), responses: [
        new OA\Response(response: 200, description: 'Task updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function update(TaskRequest $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TaskResource($this->service->update($record, $request->validated())->load(['warehouse:id,code,name', 'stockItem:id,sku,name', 'sourceBin:id,code', 'destinationBin:id,code']));
    }

    #[OA\Delete(path: '/api/v1/wms/tasks/{id}', operationId: 'deleteWmsTask', description: 'Delete a task that is open or cancelled (soft delete).', summary: 'Delete a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 204, description: 'Task deleted'),
        new OA\Response(ref: '#/components/responses/WmsHubUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsHubForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsHubNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsHubUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('delete', $record);
        $this->service->delete($record);

        return response()->noContent();
    }
}
