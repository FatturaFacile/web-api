<?php

declare(strict_types=1);

namespace Modules\DataExport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\DataExport\Http\Requests\StoreExportScheduleRequest;
use Modules\DataExport\Http\Resources\ExportScheduleResource;
use Modules\DataExport\Models\ExportSchedule;
use Modules\DataExport\Services\ExportJobService;
use OpenApi\Attributes as OA;

/**
 * Recurring exports: each due day the command `exports:run-scheduled` queues an export for the period of the schedule.
 */
final class ExportScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ExportJobService $service) {}

    /**
     * List the schedules.
     */
    #[OA\Get(
        path: '/api/v1/exports/schedules',
        operationId: 'listExportSchedules',
        description: 'The recurring exports of the company.',
        summary: 'List export schedules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        responses: [
            new OA\Response(response: 200, description: 'Schedules', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ExportScheduleResource'))])),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(ExportScheduleResource::collection(ExportSchedule::query()->orderBy('created_at')->get()));
    }

    /**
     * Create a schedule.
     */
    #[OA\Post(
        path: '/api/v1/exports/schedules',
        operationId: 'createExportSchedule',
        description: 'Creates a recurring export. The first run is tomorrow. Periods: `all`, `previous_month` or `year_to_date` (the accountant archive supports `previous_month` only).',
        summary: 'Create an export schedule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExportScheduleRequest')),
        tags: ['Data Export'],
        responses: [
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/ExportScheduleResource')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreExportScheduleRequest $request): JsonResponse
    {
        $schedule = ExportSchedule::create($request->validated() + [
            'user_id' => (string) $request->user()->id,
            'next_run_on' => $this->service->firstRun()->toDateString(),
        ]);

        return $this->successResponse(new ExportScheduleResource($schedule), 'Schedule created', Response::HTTP_CREATED);
    }

    /**
     * Update a schedule.
     */
    #[OA\Put(
        path: '/api/v1/exports/schedules/{id}',
        operationId: 'updateExportSchedule',
        description: 'Changes the dataset, cadence, period or the active flag of a schedule.',
        summary: 'Update an export schedule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreExportScheduleRequest')),
        tags: ['Data Export'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/ExportScheduleResource')])),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(StoreExportScheduleRequest $request, string $id): JsonResponse
    {
        $schedule = ExportSchedule::query()->findOrFail($id);
        $schedule->update($request->validated());

        return $this->successResponse(new ExportScheduleResource($schedule->refresh()), 'Schedule updated');
    }

    /**
     * Delete a schedule.
     */
    #[OA\Delete(
        path: '/api/v1/exports/schedules/{id}',
        operationId: 'deleteExportSchedule',
        description: 'Stops the recurring export; the exports already in the history stay.',
        summary: 'Delete an export schedule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Data Export'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        ExportSchedule::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Schedule deleted');
    }
}
