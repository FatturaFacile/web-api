<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\BusinessIntelligence\Http\Controllers\Concerns\InteractsWithBi;
use Modules\BusinessIntelligence\Http\Requests\CalculatedMeasureRequest;
use Modules\BusinessIntelligence\Http\Resources\CalculatedMeasureResource;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Services\CalculatedMeasureService;
use OpenApi\Attributes as OA;

/**
 * Calculated measures: formulas over the base measures of a dataset, computed on the aggregated rows.
 */
final class CalculatedMeasureController extends Controller
{
    use InteractsWithBi;

    public function __construct(private readonly CalculatedMeasureService $measures) {}

    #[OA\Get(path: '/api/v1/business-intelligence/calculated-measures', operationId: 'listBiCalculatedMeasures', description: 'Calculated measures of the company. Filter: dataset.', summary: 'List calculated measures', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(name: 'dataset', description: 'Dataset key', in: 'query', required: false, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Calculated measures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BiCalculatedMeasure'))])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BiCalculatedMeasure::class);
        $filters = $request->validate(['dataset' => ['nullable', 'string', 'max:40']]);

        return CalculatedMeasureResource::collection(
            BiCalculatedMeasure::query()
                ->when(isset($filters['dataset']), static fn ($query) => $query->where('dataset', $filters['dataset']))
                ->orderBy('dataset')->orderBy('key')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/business-intelligence/calculated-measures', operationId: 'storeBiCalculatedMeasure', description: 'Define a measure on a dataset the caller can read. The formula may use numbers, + - * /, parentheses and the keys of the base measures of the dataset; it must read at least one measure. The key cannot be a key already used by the dataset. Division by zero or a missing input gives an empty cell.', summary: 'Create a calculated measure', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiCalculatedMeasureRequest')), tags: ['Business Intelligence'], responses: [
        new OA\Response(response: 201, description: 'Measure created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiCalculatedMeasure')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function store(CalculatedMeasureRequest $request): JsonResponse
    {
        $this->authorize('create', BiCalculatedMeasure::class);

        return (new CalculatedMeasureResource($this->measures->create($this->tenantId(), $this->currentUser(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/business-intelligence/calculated-measures/{id}', operationId: 'showBiCalculatedMeasure', description: 'Return a calculated measure.', summary: 'Show a calculated measure', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Measure', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiCalculatedMeasure')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
    ])]
    public function show(string $id): CalculatedMeasureResource
    {
        $measure = BiCalculatedMeasure::query()->findOrFail($id);
        $this->authorize('view', $measure);

        return new CalculatedMeasureResource($measure);
    }

    #[OA\Put(path: '/api/v1/business-intelligence/calculated-measures/{id}', operationId: 'updateBiCalculatedMeasure', description: 'Change the label, formula, unit or scale. The dataset and the key never change because saved queries refer to them.', summary: 'Update a calculated measure', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BiCalculatedMeasureRequest')), tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 200, description: 'Measure updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/BiCalculatedMeasure')])),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function update(CalculatedMeasureRequest $request, string $id): CalculatedMeasureResource
    {
        $measure = BiCalculatedMeasure::query()->findOrFail($id);
        $this->authorize('update', $measure);

        return new CalculatedMeasureResource($this->measures->update($measure, $this->currentUser(), $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/business-intelligence/calculated-measures/{id}', operationId: 'deleteBiCalculatedMeasure', description: 'Delete a measure. A measure still used by a saved query or a widget cannot be deleted.', summary: 'Delete a calculated measure', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Business Intelligence'], parameters: [new OA\Parameter(ref: '#/components/parameters/BiId')], responses: [
        new OA\Response(response: 204, description: 'Measure deleted'),
        new OA\Response(ref: '#/components/responses/BiUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BiForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BiNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/BiUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $measure = BiCalculatedMeasure::query()->findOrFail($id);
        $this->authorize('delete', $measure);
        $this->measures->delete($measure);

        return response()->noContent();
    }
}
