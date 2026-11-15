<?php

declare(strict_types=1);

namespace Modules\Wms\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Wms\Http\Controllers\Concerns\InteractsWithWms;
use Modules\Wms\Http\Resources\TaskResource;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Services\TaskService;
use OpenApi\Attributes as OA;

/**
 * The RF operator flow of the task queue: claim the next task, assign, start, confirm, short, skip and cancel.
 */
final class TaskActionController extends Controller
{
    use InteractsWithWms;

    public function __construct(private readonly TaskService $tasks) {}

    #[OA\Post(path: '/api/v1/wms/tasks/claim-next', operationId: 'claimNextWmsTask', description: 'Gives the calling operator the most urgent task and starts it: first a task of the operator already in progress, then the open tasks assigned to the operator or to nobody, by priority, due date and age. Tasks of a wave that is only planned are not offered. data is null when the queue is empty.', summary: 'Claim the next task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'The claimed task, or null', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function claimNext(Request $request): JsonResponse
    {
        $this->authorize('create', WmsTask::class);
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        $task = $this->tasks->claimNext($this->tenantId(), (string) $request->user()?->id, $data['warehouse_id'] ?? null);

        return $task === null ? response()->json(['data' => null]) : (new TaskResource($task))->response();
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/assign', operationId: 'assignWmsTask', description: 'Assign an open or assigned task to a member of the company.', summary: 'Assign a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_id'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid')])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function assign(Request $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        return new TaskResource($this->tasks->assign($record, $data['user_id']));
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/start', operationId: 'startWmsTask', description: 'The operator starts an open task (taking it) or an assigned one (only the assignee can).', summary: 'Start a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function start(Request $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TaskResource($this->tasks->start($record, (string) $request->user()?->id));
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/confirm', operationId: 'confirmWmsTask', description: 'Confirm the whole planned quantity of a task in progress. A smaller quantity is refused: use short.', summary: 'Confirm a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'quantity', type: 'string', example: '10', nullable: true)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function confirm(Request $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return new TaskResource($this->tasks->confirm($record, isset($data['quantity']) ? (string) $data['quantity'] : null));
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/short', operationId: 'shortWmsTask', description: 'Close a task in progress with less than planned (zero allowed) and a reason; the rest is reported as remaining_quantity.', summary: 'Short-complete a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['quantity', 'reason'], properties: [new OA\Property(property: 'quantity', type: 'string', example: '4'), new OA\Property(property: 'reason', type: 'string', maxLength: 255)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function short(Request $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return new TaskResource($this->tasks->short($record, (string) $data['quantity'], (string) $data['reason']));
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/skip', operationId: 'skipWmsTask', description: 'The operator cannot do the task now: it goes back to the queue with a lower priority and without an assignee. After three skips it is parked as skipped for a supervisor.', summary: 'Skip a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string', maxLength: 255)])), responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function skip(Request $request, string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return new TaskResource($this->tasks->skip($record, (string) $data['reason']));
    }

    #[OA\Post(path: '/api/v1/wms/tasks/{id}/cancel', operationId: 'cancelWmsTask', description: 'Cancel a task that is not closed yet.', summary: 'Cancel a task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['WMS'], parameters: [new OA\Parameter(ref: '#/components/parameters/WmsId')], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WmsTask')])),
        new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
    ])]
    public function cancel(string $id): TaskResource
    {
        $record = WmsTask::query()->findOrFail($id);
        $this->authorize('update', $record);

        return new TaskResource($this->tasks->cancel($record));
    }
}
