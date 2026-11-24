<?php

declare(strict_types=1);

namespace Modules\Hospitality\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Hospitality\Http\Requests\LodgingRequest;
use Modules\Hospitality\Http\Resources\HospitalityResource;
use Modules\Hospitality\Models\HousekeepingTask;
use Modules\Hospitality\Services\LodgingService;
use OpenApi\Attributes as OA;

/**
 * Housekeeping board: cleaning and inspection tasks per room.
 */
final class HousekeepingController extends Controller
{
    public function __construct(private readonly LodgingService $lodging) {}

    #[OA\Get(path: '/api/v1/hospitality/housekeeping-tasks', operationId: 'listHospitalityHousekeepingTasks', description: 'Tasks by due date with their room. Filters: status, due_on.', summary: 'List housekeeping tasks', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'done'])),
        new OA\Parameter(name: 'due_on', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [
        new OA\Response(response: 200, description: 'Tasks', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordCollection')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', HousekeepingTask::class);

        return HospitalityResource::collection(
            HousekeepingTask::query()->with('accommodation')
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('due_on'), static fn ($query) => $query->where('due_on', (string) $request->query('due_on')))
                ->orderBy('due_on')->orderBy('created_at')->gridPaginate(RequestedPerPage::resolve(50))
        );
    }

    #[OA\Post(path: '/api/v1/hospitality/housekeeping-tasks', operationId: 'storeHospitalityHousekeepingTask', description: 'Schedules a cleaning or inspection of a room (check-out clean-ups are created automatically).', summary: 'Create a housekeeping task', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityHousekeepingTaskRequest')), tags: ['Hospitality'], responses: [
        new OA\Response(response: 201, description: 'Task created', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function store(LodgingRequest $request): JsonResponse
    {
        $this->authorize('create', HousekeepingTask::class);

        return (new HospitalityResource(HousekeepingTask::create([...$request->validated(), 'due_on' => $request->validated('due_on') ?? now()->toDateString(), 'status' => 'pending'])))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/hospitality/housekeeping-tasks/{id}/start', operationId: 'startHospitalityHousekeepingTask', description: 'Starts a pending task, optionally assigning it to a user.', summary: 'Start a housekeeping task', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/HospitalityHousekeepingStartRequest')), tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Task started', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function start(LodgingRequest $request, string $id): HospitalityResource
    {
        return new HospitalityResource($this->lodging->startTask($this->task($id), $request->validated('assigned_to')));
    }

    #[OA\Post(path: '/api/v1/hospitality/housekeeping-tasks/{id}/complete', operationId: 'completeHospitalityHousekeepingTask', description: 'Completes a task: the room becomes clean (inspected for an inspection task) and can be assigned at check-in.', summary: 'Complete a housekeeping task', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Hospitality'], parameters: [new OA\Parameter(ref: '#/components/parameters/HospitalityId')], responses: [
        new OA\Response(response: 200, description: 'Task completed', content: new OA\JsonContent(ref: '#/components/schemas/HospitalityRecordResponse')),
        new OA\Response(ref: '#/components/responses/HospitalityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/HospitalityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/HospitalityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/HospitalityUnprocessable', response: 422),
    ])]
    public function complete(string $id): HospitalityResource
    {
        return new HospitalityResource($this->lodging->completeTask($this->task($id)));
    }

    private function task(string $id): HousekeepingTask
    {
        $task = HousekeepingTask::query()->findOrFail($id);
        $this->authorize('update', $task);

        return $task;
    }
}
