<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Analytics\Http\Requests\SaveReportDefinitionRequest;
use Modules\Analytics\Http\Resources\ReportDefinitionResource;
use Modules\Analytics\Models\ReportDefinition;
use Modules\Analytics\Services\KpiCsvExporter;
use Modules\Analytics\Services\ReportDefinitionRunner;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Saved report definitions of the user: which indicators, which period, which comparison.
 */
final class ReportDefinitionController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly ReportDefinitionRunner $runner, private readonly KpiCsvExporter $exporter) {}

    /**
     * List definitions.
     */
    #[OA\Get(
        path: '/api/v1/analytics/report-definitions',
        operationId: 'listAnalyticsReportDefinitions',
        description: 'Definitions saved by the caller plus the ones shared with the company, newest first.',
        summary: 'List saved reports',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        responses: [
            new OA\Response(response: 200, description: 'Saved reports', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsReportDefinition'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $definitions = $this->visible($request)
            ->latest()
            ->gridPaginate(min(200, max(1, (int) $request->query('per_page', 200))));

        return $this->successResponse(
            data: ReportDefinitionResource::collection($definitions),
            message: 'Report definitions retrieved',
            meta: ['current_page' => $definitions->currentPage(), 'last_page' => $definitions->lastPage(), 'per_page' => $definitions->perPage(), 'total' => $definitions->total()],
        );
    }

    /**
     * Save a definition.
     */
    #[OA\Post(
        path: '/api/v1/analytics/report-definitions',
        operationId: 'createAnalyticsReportDefinition',
        description: 'Saves a report for the caller. Presets such as `month_to_date` move with the calendar; `custom` needs both dates.',
        summary: 'Save a report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveReportDefinitionRequest')),
        tags: ['Analytics'],
        responses: [
            new OA\Response(response: 201, description: 'Saved', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsReportDefinition')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function store(SaveReportDefinitionRequest $request): JsonResponse
    {
        $definition = ReportDefinition::create([...$request->definitionAttributes(), 'user_id' => $request->user()->id]);

        return $this->successResponse(new ReportDefinitionResource($definition), 'Report definition saved', HttpResponse::HTTP_CREATED);
    }

    /**
     * Show a definition.
     */
    #[OA\Get(
        path: '/api/v1/analytics/report-definitions/{id}',
        operationId: 'showAnalyticsReportDefinition',
        description: 'A definition of the caller or shared with the company.',
        summary: 'Show a saved report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDefinitionId')],
        responses: [
            new OA\Response(response: 200, description: 'The definition', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsReportDefinition')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new ReportDefinitionResource($this->find($request, $id)));
    }

    /**
     * Update a definition.
     */
    #[OA\Put(
        path: '/api/v1/analytics/report-definitions/{id}',
        operationId: 'updateAnalyticsReportDefinition',
        description: 'Changes a saved report. Only its owner, or an owner or administrator of the company, can.',
        summary: 'Update a saved report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsSaveReportDefinitionRequest')),
        tags: ['Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDefinitionId')],
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsReportDefinition')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function update(SaveReportDefinitionRequest $request, string $id): JsonResponse
    {
        $definition = $this->find($request, $id);
        $this->authorize('manage', $definition);
        $definition->update($request->definitionAttributes());

        return $this->successResponse(new ReportDefinitionResource($definition), 'Report definition updated');
    }

    /**
     * Delete a definition.
     */
    #[OA\Delete(
        path: '/api/v1/analytics/report-definitions/{id}',
        operationId: 'deleteAnalyticsReportDefinition',
        description: 'Deletes a saved report. Only its owner, or an owner or administrator of the company, can.',
        summary: 'Delete a saved report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDefinitionId')],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $definition = $this->find($request, $id);
        $this->authorize('manage', $definition);
        $definition->delete();

        return $this->successResponse(null, 'Report definition deleted');
    }

    /**
     * Run a definition.
     */
    #[OA\Get(
        path: '/api/v1/analytics/report-definitions/{id}/run',
        operationId: 'runAnalyticsReportDefinition',
        description: 'Calculates the saved report for today: presets are resolved on the calendar of the company. Indicators the caller cannot read come back with `available: false`.',
        summary: 'Run a saved report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDefinitionId')],
        responses: [
            new OA\Response(response: 200, description: 'The KPI report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsKpiReport')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function run(Request $request, string $id): JsonResponse
    {
        return $this->successResponse($this->runner->run($this->find($request, $id), $request->user()), 'Report calculated');
    }

    /**
     * Export a definition.
     */
    #[OA\Get(
        path: '/api/v1/analytics/report-definitions/{id}/export',
        operationId: 'exportAnalyticsReportDefinition',
        description: 'Runs the saved report and returns it as an Excel-compatible CSV, one row per indicator. Text cells are neutralised against spreadsheet formula injection.',
        summary: 'Export a saved report to CSV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDefinitionId')],
        responses: [
            new OA\Response(response: 200, description: 'CSV file', content: new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function export(Request $request, string $id): Response
    {
        $definition = $this->find($request, $id);

        return response($this->exporter->summary($this->runner->run($definition, $request->user())), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="report-'.$definition->id.'.csv"',
        ]);
    }

    /**
     * @return Builder<ReportDefinition>
     */
    private function visible(Request $request): Builder
    {
        return ReportDefinition::query()->where(static fn (Builder $query): Builder => $query->where('is_shared', true)->orWhere('user_id', $request->user()->id));
    }

    private function find(Request $request, string $id): ReportDefinition
    {
        return $this->visible($request)->findOrFail($id);
    }
}
