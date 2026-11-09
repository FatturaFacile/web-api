<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\GeneralLedger\Http\Requests\StorePeriodCloseTaskRequest;
use Modules\GeneralLedger\Http\Requests\UpdatePeriodCloseTaskStatusRequest;
use Modules\GeneralLedger\Http\Resources\LedgerDateFormat;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\PeriodCloseTask;
use Modules\GeneralLedger\Services\PeriodCloseChecklistService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The close checklist of a fiscal period: automatic checks and the tasks of the users.
 */
final class PeriodCloseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PeriodCloseChecklistService $checklist) {}

    /**
     * The checklist of a period.
     */
    #[OA\Get(
        path: '/api/v1/general-ledger/fiscal-periods/{id}/close-checklist',
        operationId: 'getGeneralLedgerPeriodCloseChecklist',
        description: 'Close checklist of a fiscal period. Automatic checks read from the ledger: drafts not posted, journals waiting for approval, recurring runs and automatic reversals due and not posted, entries whose lines do not balance (all blocking), and control accounts differing from their open items (informative). Plus the tasks added by the users; open blocking tasks stop the closing. `can_close` tells whether POST /fiscal-periods/{id}/close will be accepted.',
        summary: 'Get period close checklist',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Checklist', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerPeriodCloseChecklist')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Period not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $checklist = $this->checklist->checklist(FiscalPeriod::findOrFail($id));
        $checklist['tasks'] = array_map(fn (PeriodCloseTask $task): array => $this->task($task), $checklist['tasks']);

        return $this->successResponse($checklist);
    }

    /**
     * Adds a task to the checklist.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-periods/{id}/close-tasks',
        operationId: 'createGeneralLedgerPeriodCloseTask',
        description: 'Adds a task to the close checklist of an open period (for example "Bank reconciliation signed off"). A blocking task (default) must be done or waived before the period can be closed. Refused with 409 when the period is closed.',
        summary: 'Add period close task',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['title'], properties: [
            new OA\Property(property: 'title', type: 'string', maxLength: 190, example: 'Bank reconciliation signed off'),
            new OA\Property(property: 'description', type: 'string', maxLength: 1000, nullable: true),
            new OA\Property(property: 'is_blocking', type: 'boolean', default: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Task added', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerPeriodCloseTask')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Period not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Period closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeTask(StorePeriodCloseTaskRequest $request, string $id): JsonResponse
    {
        $task = $this->checklist->addTask(FiscalPeriod::findOrFail($id), $request->validated(), (string) $request->user()->id);

        return $this->successResponse($this->task($task), 'Close task added', Response::HTTP_CREATED);
    }

    /**
     * Marks a task done, waived or open.
     */
    #[OA\Post(
        path: '/api/v1/general-ledger/fiscal-periods/{id}/close-tasks/{taskId}/status',
        operationId: 'updateGeneralLedgerPeriodCloseTaskStatus',
        description: 'Marks a close task done, waived (a note with the reason is required) or open again; who did it and when are recorded. Refused with 409 when the period is closed.',
        summary: 'Update period close task status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['General Ledger Fiscal Periods'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'taskId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['status'], properties: [
            new OA\Property(property: 'status', type: 'string', enum: ['open', 'done', 'waived'], example: 'done'),
            new OA\Property(property: 'note', type: 'string', maxLength: 1000, nullable: true, description: 'Required when waived.'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Task updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerPeriodCloseTask')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Period or task not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Period closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateTaskStatus(UpdatePeriodCloseTaskStatusRequest $request, string $id, string $taskId): JsonResponse
    {
        $period = FiscalPeriod::findOrFail($id);
        $task = PeriodCloseTask::where('fiscal_period_id', $period->id)->findOrFail($taskId);
        $task = $this->checklist->setTaskStatus($task, (string) $request->validated('status'), $request->validated('note'), (string) $request->user()->id);

        return $this->successResponse($this->task($task), 'Close task updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function task(PeriodCloseTask $task): array
    {
        return [
            'id' => (string) $task->id,
            'fiscal_period_id' => (string) $task->fiscal_period_id,
            'title' => $task->title,
            'description' => $task->description,
            'is_blocking' => (bool) $task->is_blocking,
            'status' => $task->status,
            'created_by' => $task->created_by,
            'completed_by' => $task->completed_by,
            'completed_at' => LedgerDateFormat::instant($task->completed_at),
            'completion_note' => $task->completion_note,
        ];
    }
}
