<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Analytics\Semantic\Dataset;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\SemanticCatalog;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The semantic layer as the business intelligence screens see it: the datasets of the Analytics catalogue the caller can
 * read, with the calculated measures of the company added to their measures.
 */
final class CatalogController extends Controller
{
    public function __construct(private readonly SemanticCatalog $catalog, private readonly ReportEngine $engine) {}

    #[OA\Get(path: '/api/v1/business-intelligence/datasets', operationId: 'listBusinessIntelligenceDatasets', description: 'Datasets of the semantic layer the caller may read (the module of their data decides), with their dimensions, their measures and the calculated measures defined by the company on each of them. Joins and columns are fixed by the dataset declaration: nothing else is reachable.', summary: 'List datasets', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 200, description: 'Datasets', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiDataset'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function index(Request $request): JsonResponse
    {
        $calculated = BiCalculatedMeasure::query()->orderBy('key')->get()->groupBy('dataset');
        $datasets = array_values(array_map(fn (Dataset $dataset): array => $this->describe($dataset, $calculated->get($dataset->key, collect())->all()), $this->catalog->readableBy($request->user())));

        return response()->json(['data' => $datasets]);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/datasets/{dataset}', operationId: 'showBusinessIntelligenceDataset', description: 'One dataset with its calculated measures.', summary: 'Show a dataset', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiDatasetKey')], responses: [
        new OA\Response(response: 200, description: 'Dataset', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiDataset')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function show(Request $request, string $dataset): JsonResponse
    {
        $found = $this->catalog->find($dataset) ?? throw new NotFoundHttpException(__('analytics_errors.report.unknown_dataset'));
        $dataset = $this->engine->datasetFor($found->key, $request->user());

        return response()->json(['data' => $this->describe($dataset, BiCalculatedMeasure::query()->where('dataset', $dataset->key)->orderBy('key')->get()->all())]);
    }

    /**
     * @param  list<BiCalculatedMeasure>  $calculated
     * @return array<string, mixed>
     */
    private function describe(Dataset $dataset, array $calculated): array
    {
        $description = $dataset->toArray();
        $description['calculated_measures'] = array_map(static fn (BiCalculatedMeasure $measure): array => [
            'key' => $measure->key,
            'label' => $measure->label,
            'unit' => $measure->unit,
            'formula' => $measure->formula,
        ], $calculated);

        return $description;
    }
}
