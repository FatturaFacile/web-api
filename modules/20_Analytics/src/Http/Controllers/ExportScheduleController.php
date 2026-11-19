<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Analytics\Application\Commands\ConfigureReportScheduleCommand;
use Modules\Analytics\Application\Commands\ConfigureReportScheduleHandler;
use Modules\Analytics\Application\Commands\TriggerScheduledReportRunCommand;
use Modules\Analytics\Application\Commands\TriggerScheduledReportRunHandler;
use Modules\Analytics\Http\Requests\SaveExportScheduleRequest;
use Modules\Analytics\Http\Resources\ExportScheduleResource;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Scheduling\CronSchedule;
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
    public function __construct(
        private readonly ScheduledExportService $scheduler,
        private readonly ReportEngine $engine,
        private readonly ConfigureReportScheduleHandler $configurer,
        private readonly TriggerScheduledReportRunHandler $trigger,
        private readonly CronSchedule $cron,
    ) {}

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
        if ($request->isCron()) {
            $outcome = $this->configurer->handle(new ConfigureReportScheduleCommand($request->user(), null, $request->settings()));

            return $this->successResponse(['schedule' => new ExportScheduleResource($outcome['schedule']), 'webhook_secret' => $outcome['webhook_secret']], 'Schedule created', HttpResponse::HTTP_CREATED);
        }

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

        if ($request->isCron()) {
            $outcome = $this->configurer->handle(new ConfigureReportScheduleCommand($request->user(), $id, $request->settings()));

            return $this->successResponse(['schedule' => new ExportScheduleResource($outcome['schedule']), 'webhook_secret' => $outcome['webhook_secret']], 'Schedule updated');
        }

        $attributes = $request->scheduleAttributes();
        if ((string) $attributes['designed_report_id'] !== (string) $schedule->designed_report_id) {
            $this->usableReport($request, (string) $attributes['designed_report_id']);
        }
        $schedule->update([
            ...$attributes,
            'cron_expression' => null,
            'next_run_at' => null,
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

    /**
     * Queues a run of the schedule now ("Run now"), outside its calendar.
     */
    #[OA\Post(
        path: '/api/v1/analytics/export-schedules/{id}/run',
        operationId: 'runAnalyticsExportScheduleNow',
        description: 'Queues the generation and delivery of the schedule right away, with the locale, format and channels it has. The calendar is not changed. The outcome appears on the schedule (`last_status`) and in the execution log.',
        summary: 'Run a scheduled export now',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 202, description: 'Queued', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function run(Request $request, string $id): JsonResponse
    {
        $schedule = $this->trigger->handle(new TriggerScheduledReportRunCommand($request->user(), $id));

        return $this->successResponse(new ExportScheduleResource($schedule), 'Run queued', HttpResponse::HTTP_ACCEPTED);
    }

    /**
     * The next runs of a cron expression in a time zone.
     */
    #[OA\Post(
        path: '/api/v1/analytics/export-schedules/cron-preview',
        operationId: 'previewAnalyticsCronExpression',
        description: 'Validates a cron expression (it cannot run more often than every 5 minutes) and returns its next five runs on the wall clock of the time zone.',
        summary: 'Preview a cron expression',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['cron_expression'], properties: [new OA\Property(property: 'cron_expression', type: 'string', example: '0 7 * * 1'), new OA\Property(property: 'timezone', type: 'string', example: 'Europe/Zurich')])),
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'The next runs', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'next_runs', type: 'array', items: new OA\Items(type: 'string', format: 'date-time'))])])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function cronPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['cron_expression' => ['required', 'string', 'max:100'], 'timezone' => ['nullable', 'timezone:all']]);
        $timezone = (string) ($data['timezone'] ?? 'Europe/Zurich');

        try {
            $this->cron->assertValid($data['cron_expression'], $timezone);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cron_expression' => [(string) __($exception->getMessage())]]);
        }

        return $this->successResponse(['next_runs' => $this->cron->preview($data['cron_expression'], $timezone)]);
    }

    /**
     * The execution log: the recent exports of the company, scheduled or not.
     */
    #[OA\Get(
        path: '/api/v1/analytics/execution-logs',
        operationId: 'listAnalyticsExecutionLogs',
        description: 'Status (queued, running, completed, failed), row count, duration and error of the latest report exports. Owners and administrators see all of them, other users their own. Filter by `designed_report_id` or `schedule_id`.',
        summary: 'List report executions',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'Executions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
        ]
    )]
    public function logs(Request $request): JsonResponse
    {
        $filters = $request->validate(['designed_report_id' => ['nullable', 'uuid'], 'schedule_id' => ['nullable', 'uuid']]);
        $runs = ExportRun::query()
            ->when(! $this->isAdministrator($request), static fn (Builder $query): Builder => $query->where('user_id', $request->user()->id))
            ->when($filters['designed_report_id'] ?? null, static fn (Builder $query, string $id): Builder => $query->where('designed_report_id', $id))
            ->when($filters['schedule_id'] ?? null, static fn (Builder $query, string $id): Builder => $query->where('schedule_id', $id))
            ->latest()
            ->limit(100)
            ->get();

        return $this->successResponse($runs->map(static fn (ExportRun $run): array => [
            'id' => $run->id,
            'designed_report_id' => $run->designed_report_id,
            'schedule_id' => $run->schedule_id,
            'dataset' => $run->dataset,
            'format' => $run->format,
            'locale' => $run->locale,
            'status' => $run->status,
            'row_count' => $run->row_count,
            'truncated' => $run->truncated,
            'duration_ms' => $run->duration_ms,
            'size_bytes' => $run->size_bytes,
            'error_message' => $run->error_message,
            'executed_at' => $run->created_at?->toIso8601String(),
        ])->all());
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
