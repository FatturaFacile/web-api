<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analytics\Http\Requests\DesignerRequest;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\SemanticCatalog;
use Modules\Analytics\Services\ReportExporter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The catalogue of the semantic layer and the ad-hoc runs of the report designer: what can be reported on, and a report
 * that is not saved yet.
 */
final class SemanticLayerController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly SemanticCatalog $catalog, private readonly ReportEngine $engine, private readonly ReportExporter $exporter) {}

    /**
     * Datasets the caller can report on.
     */
    #[OA\Get(
        path: '/api/v1/analytics/semantic/datasets',
        operationId: 'listAnalyticsSemanticDatasets',
        description: 'The governed catalogue: datasets with their allow-listed dimensions and measures. Only datasets of modules the caller can read are listed.',
        summary: 'List datasets of the semantic layer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'Datasets', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AnalyticsSemanticDataset'))])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $datasets = array_values(array_map(static fn ($dataset): array => $dataset->toArray(), $this->catalog->readableBy($request->user())));

        return $this->successResponse($datasets, 'Datasets retrieved');
    }

    /**
     * One dataset.
     */
    #[OA\Get(
        path: '/api/v1/analytics/semantic/datasets/{dataset}',
        operationId: 'showAnalyticsSemanticDataset',
        summary: 'Show a dataset of the semantic layer',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Analytics BI'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/AnaDatasetKey')],
        responses: [
            new OA\Response(response: 200, description: 'The dataset', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsSemanticDataset')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $dataset): JsonResponse
    {
        $found = $this->catalog->find($dataset) ?? throw new NotFoundHttpException('Unknown dataset.');

        return $this->successResponse($this->engine->datasetFor($found->key, $request->user())->toArray());
    }

    /**
     * Run a report that is not saved.
     */
    #[OA\Post(
        path: '/api/v1/analytics/designer/run',
        operationId: 'runAnalyticsDesignerReport',
        description: 'Runs a report from a dataset and a specification, one page at a time, always on the active company. Cancelled with a 422 when it takes longer than the time limit. It is a POST, so it needs write access to the analytics module like saving a report.',
        summary: 'Run an ad-hoc report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsDesignerRequest')),
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'One page of the report', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AnalyticsReportResult')])),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function run(DesignerRequest $request): JsonResponse
    {
        $spec = $this->engine->parse((string) $request->input('dataset'), (array) $request->input('spec'), $request->user(), 'spec.');

        return $this->successResponse($this->engine->run($spec, $request->user(), (int) $request->input('page', 1), (int) $request->input('per_page', 50)), 'Report calculated');
    }

    /**
     * Export a report that is not saved.
     */
    #[OA\Post(
        path: '/api/v1/analytics/designer/export',
        operationId: 'exportAnalyticsDesignerReport',
        description: 'Exports the report as CSV, Excel-compatible CSV or xlsx (field `format`), up to the row limit (the response header `X-Report-Truncated` says whether it was cut). Text cells are neutralised against spreadsheet formula injection. The export is audit logged.',
        summary: 'Export an ad-hoc report',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AnalyticsDesignerRequest')),
        tags: ['Analytics BI'],
        responses: [
            new OA\Response(response: 200, description: 'CSV or xlsx file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/AnaUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/AnaForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/AnaUnprocessable', response: 422),
        ]
    )]
    public function export(DesignerRequest $request): StreamedResponse
    {
        $spec = $this->engine->parse((string) $request->input('dataset'), (array) $request->input('spec'), $request->user(), 'spec.');

        return $this->exporter->response($this->exporter->prepare($spec, $request->user(), (string) $request->input('format', 'csv')));
    }
}
