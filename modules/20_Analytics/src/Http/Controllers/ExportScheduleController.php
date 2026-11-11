<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analytics\Http\Requests\SaveExportScheduleRequest;
use Modules\Analytics\Http\Resources\ExportScheduleResource;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Services\ScheduledExportService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Scheduled exports of designed reports. A schedule runs as the user who creates it, so it never exports more than that
 * user could download.
 */
final class ExportScheduleController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly ScheduledExportService $scheduler, private readonly ReportEngine $engine) {}

    /**
     * List scheduled exports.
     */
    #[OA\Get(
        path: '/api/v1/analytics/export-schedules',
        operationId: 'listAnalyticsExportSchedules',
        description: 'The schedules of the caller; owners and administrators of the company see all of them.',
        summary: 'List scheduled exports',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'Schedules', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsExportSchedule'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $schedules = ExportSchedule::query()->when(! $this->isAdministrator($request), static fn (Builder $query): Builder => $query->where('user_id', $request->user()->id))->latest()->limit(200)->get();

        return $this->successResponse(ExportScheduleResource::collection($schedules), 'Schedules retrieved');
    }

    /**
     * Create a scheduled export.
     */
    #[OA\Post(
        path: '/api/v1/analytics/export-schedules',
        operationId: 'createAnalyticsExportSchedule',
        description: 'Schedules the export of a report the caller can open, daily, weekly or monthly. The command `analytics:run-scheduled-exports` runs it (06:00) and delivers an e-mail with a signed link to the file, a document in the Documents module, or both. The first run is tomorrow or the first matching day after.',
        summary: 'Schedule an export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveExportScheduleRequest')),
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 201, description: 'Scheduled', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsExportSchedule')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function store(SaveExportScheduleRequest $request): JsonResponse
    {
        $attributes = $request->scheduleAttributes();
        $this->usableReport($request, (string) $attributes['designed_report_id']);

        $schedule = ExportSchedule::create([
            ...$attributes,
            'user_id' => $request->user()->id,
            'next_run_on' => $this->scheduler->firstRunOn($attributes['frequency'], $attributes['day_of_week'], $attributes['day_of_month'])->toDateString(),
        ]);

        return $this->successResponse(new ExportScheduleResource($schedule), 'Schedule created', HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a scheduled export.
     */
    #[OA\Get(
        path: '/api/v1/analytics/export-schedules/{id}',
        operationId: 'showAnalyticsExportSchedule',
        summary: 'Show a scheduled export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaScheduleId')],
        responses: [
            new OA\Response(response: 200, description: 'The schedule', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsExportSchedule')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new ExportScheduleResource($this->find($request, $id)));
    }

    /**
     * Update a scheduled export.
     */
    #[OA\Put(
        path: '/api/v1/analytics/export-schedules/{id}',
        operationId: 'updateAnalyticsExportSchedule',
        description: 'Only the owner of the schedule, or an owner or administrator of the company, can change it. The next run is recalculated; the schedule keeps running as its original owner.',
        summary: 'Update a scheduled export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveExportScheduleRequest')),
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaScheduleId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsExportSchedule')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function update(SaveExportScheduleRequest $request, string $id): JsonResponse
    {
        $schedule = $this->find($request, $id);
        $attributes = $request->scheduleAttributes();
        if ((string) $attributes['designed_report_id'] !== (string) $schedule->designed_report_id) {
            $this->usableReport($request, (string) $attributes['designed_report_id']);
        }
        $schedule->update([
            ...$attributes,
            'next_run_on' => $this->scheduler->firstRunOn($attributes['frequency'], $attributes['day_of_week'], $attributes['day_of_month'])->toDateString(),
        ]);

        return $this->successResponse(new ExportScheduleResource($schedule), 'Schedule updated');
    }

    /**
     * Delete a scheduled export.
     */
    #[OA\Delete(
        path: '/api/v1/analytics/export-schedules/{id}',
        operationId: 'deleteAnalyticsExportSchedule',
        summary: 'Delete a scheduled export',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaScheduleId')],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return $this->successResponse(null, 'Schedule deleted');
    }

    private function find(Request $request, string $id): ExportSchedule
    {
        $schedule = ExportSchedule::query()->findOrFail($id);
        $this->authorize('manage', $schedule);

        return $schedule;
    }

    /**
     * The report must be one the caller may open, on a dataset the caller can read.
     */
    private function usableReport(Request $request, string $id): DesignedReport
    {
        $report = DesignedReport::query()->visibleTo($request->user())->find($id);

        if ($report === null) {
            abort(HttpResponse::HTTP_UNPROCESSABLE_ENTITY, 'The report does not exist or is not shared with you.');
        }

        $this->engine->datasetFor($report->dataset, $request->user());

        return $report;
    }

    private function isAdministrator(Request $request): bool
    {
        return in_array($request->user()->getRoleInTenant((string) app(TenantContext::class)->getTenantId()), ['owner', 'admin'], true);
    }
}
