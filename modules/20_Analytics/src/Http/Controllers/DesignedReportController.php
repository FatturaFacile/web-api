<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Analytics\Application\Commands\CreateOrUpdateSavedReportCommand;
use Modules\Analytics\Application\Commands\CreateOrUpdateSavedReportHandler;
use Modules\Analytics\Application\Queries\ExecuteAdHocPivotHandler;
use Modules\Analytics\Application\Queries\ExecuteAdHocPivotQuery;
use Modules\Analytics\Application\Queries\ExportReportStreamHandler;
use Modules\Analytics\Application\Queries\ExportReportStreamQuery;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\Analytics\Http\Requests\SaveDesignedReportRequest;
use Modules\Analytics\Http\Resources\DesignedReportResource;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Services\ReportExportFormatter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports designed on the semantic layer: saved, shared, run page by page and exported.
 */
final class DesignedReportController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly CreateOrUpdateSavedReportHandler $saver,
        private readonly ExportReportStreamHandler $exports,
        private readonly ExecuteAdHocPivotHandler $pivots,
    ) {}

    /**
     * List designed reports.
     */
    #[OA\Get(
        path: '/api/v1/analytics/designed-reports',
        operationId: 'listAnalyticsDesignedReports',
        description: 'Reports designed by the caller, shared with the company or shared with the caller, newest first.',
        summary: 'List designed reports',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'Designed reports', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsDesignedReport'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $reports = DesignedReport::query()->visibleTo($request->user())->latest()->limit(200)->get();

        return $this->successResponse(DesignedReportResource::collection($reports), 'Designed reports retrieved');
    }

    /**
     * Save a designed report.
     */
    #[OA\Post(
        path: '/api/v1/analytics/designed-reports',
        operationId: 'createAnalyticsDesignedReport',
        description: 'Saves the specification (never SQL). The caller must be able to read the module of the dataset. Share it with the whole company (`is_shared`) or with named members (`shared_with`).',
        summary: 'Save a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveDesignedReportRequest')),
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 201, description: 'Saved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsDesignedReport')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function store(SaveDesignedReportRequest $request): JsonResponse
    {
        $report = $this->saver->handle($this->command($request, null));

        return $this->successResponse(new DesignedReportResource($report), 'Designed report saved', HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a designed report.
     */
    #[OA\Get(
        path: '/api/v1/analytics/designed-reports/{id}',
        operationId: 'showAnalyticsDesignedReport',
        summary: 'Show a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId')],
        responses: [
            new OA\Response(response: 200, description: 'The report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsDesignedReport')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new DesignedReportResource($this->find($request, $id)));
    }

    /**
     * Update a designed report.
     */
    #[OA\Put(
        path: '/api/v1/analytics/designed-reports/{id}',
        operationId: 'updateAnalyticsDesignedReport',
        description: 'Only the owner, or an owner or administrator of the company, can change a report.',
        summary: 'Update a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveDesignedReportRequest')),
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsDesignedReport')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function update(SaveDesignedReportRequest $request, string $id): JsonResponse
    {
        $report = $this->saver->handle($this->command($request, $id));

        return $this->successResponse(new DesignedReportResource($report), 'Designed report updated');
    }

    /**
     * Delete a designed report.
     */
    #[OA\Delete(
        path: '/api/v1/analytics/designed-reports/{id}',
        operationId: 'deleteAnalyticsDesignedReport',
        description: 'Only the owner, or an owner or administrator of the company, can delete a report. Its schedules are removed with it.',
        summary: 'Delete a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId')],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $report = $this->find($request, $id);
        $this->authorize('manage', $report);
        $report->schedules()->delete();
        $report->delete();

        return $this->successResponse(null, 'Designed report deleted');
    }

    /**
     * Run a designed report.
     */
    #[OA\Get(
        path: '/api/v1/analytics/designed-reports/{id}/run',
        operationId: 'runAnalyticsDesignedReport',
        description: 'Runs the saved specification with the permissions of the caller (not of the owner): a report shared with a user who cannot read the module of its dataset answers 403. Presets of the period are resolved today, on the calendar of the company.',
        summary: 'Run a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId'), new OA\Parameter(ref: '#/components/parameters/AnaPage'), new OA\Parameter(ref: '#/components/parameters/AnaPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'One page of the report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsReportResult')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function run(Request $request, string $id): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('analytics.reporting.max_per_page', 500)]]);
        $report = $this->find($request, $id);
        $spec = $this->engine->parse($report->dataset, $report->spec, $request->user());

        return $this->successResponse($this->engine->run($spec, $request->user(), (int) $request->query('page', 1), (int) $request->query('per_page', 50)), 'Report calculated');
    }

    /**
     * Export a designed report.
     */
    #[OA\Get(
        path: '/api/v1/analytics/designed-reports/{id}/export',
        operationId: 'exportAnalyticsDesignedReport',
        description: 'Runs the report (a pivot report is exported as its matrix with subtotals and totals) and returns it as CSV, Excel-compatible CSV, xlsx, PDF or JSON (query `format`) in a regional locale (query `locale`), up to the row limit (header `X-Report-Truncated` says whether it was cut). Text cells are neutralised against spreadsheet formula injection. Every export is audit logged.',
        summary: 'Export a designed report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId'), new OA\Parameter(ref: '#/components/parameters/AnaExportFormat'), new OA\Parameter(ref: '#/components/parameters/AnaExportLocale')],
        responses: [
            new OA\Response(response: 200, description: 'CSV or xlsx file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function export(Request $request, string $id): StreamedResponse
    {
        $data = $request->validate(['format' => ['nullable', Rule::in(ReportExportFormatter::Formats)], 'locale' => ['nullable', Rule::in(RegionalReportFormatter::Locales)]]);
        $report = $this->find($request, $id);

        return $this->exports->handle(new ExportReportStreamQuery($request->user(), $this->definitionOf($report), (string) ($data['format'] ?? 'csv'), $data['locale'] ?? null, $report));
    }

    private function command(SaveDesignedReportRequest $request, ?string $id): CreateOrUpdateSavedReportCommand
    {
        return new CreateOrUpdateSavedReportCommand(
            $request->user(),
            $id,
            (string) $request->input('name'),
            $request->input('description'),
            $request->definition(),
            $request->visibility(),
            array_values(array_map('strval', (array) $request->input('shared_role_ids', []))),
            array_values(array_map('strval', (array) $request->input('shared_with', []))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function definitionOf(DesignedReport $report): array
    {
        return ['dataset' => $report->dataset, 'spec' => $report->spec, 'pivot' => $report->pivot, 'calculated_fields' => $report->calculated_fields, 'conditional_formatting' => $report->conditional_formatting, 'chart_config' => $report->chart_config];
    }

    /**
     * Runs the saved definition as a pivot (matrix with subtotals, grand totals, calculated fields, formatting and chart).
     */
    #[OA\Get(
        path: '/api/v1/analytics/designed-reports/{id}/pivot',
        operationId: 'pivotAnalyticsDesignedReport',
        description: 'Runs the saved definition as a pivot with the permissions of the caller (not of the owner).',
        summary: 'Run a designed report as a pivot',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDesignedReportId')],
        responses: [
            new OA\Response(response: 200, description: 'The pivot matrix', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', additionalProperties: true)])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function pivot(Request $request, string $id): JsonResponse
    {
        $report = $this->find($request, $id);

        return $this->successResponse($this->pivots->handle(new ExecuteAdHocPivotQuery($request->user(), $this->definitionOf($report))), 'Pivot calculated');
    }

    private function find(Request $request, string $id): DesignedReport
    {
        return DesignedReport::query()->visibleTo($request->user())->findOrFail($id);
    }
}
