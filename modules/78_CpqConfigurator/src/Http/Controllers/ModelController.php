<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Requests\CpqModelRequest;
use Modules\CpqConfigurator\Http\Requests\ExpressionCheckRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Services\CpqModelService;
use Modules\CpqConfigurator\Services\ModelDefinitionLoader;
use Modules\CpqConfigurator\Services\ModelDiagnostics;
use OpenApi\Attributes as OA;

/**
 * The configurable models: definition, publication, diagnostics and the check of an expression. Members see the
 * published models; who may manage the configurator also sees drafts and archived models.
 */
final class ModelController extends Controller
{
    public function __construct(
        private readonly CpqModelService $models,
        private readonly ModelDefinitionLoader $loader,
        private readonly ModelDiagnostics $diagnostics,
    ) {}

    #[OA\Get(path: '/api/v1/cpq-configurator/models', operationId: 'listCpqModels', summary: 'List CPQ configurable models', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Models')])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CpqModel::class);

        $models = CpqModel::query()
            ->when(! $request->user()?->can('manage', CpqModel::class), static fn ($query) => $query->where('status', CpqModel::Published))
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderBy('code')
            ->limit(500)
            ->get();

        return CpqRecordResource::collection($models);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models', operationId: 'storeCpqModel', summary: 'Create a CPQ model as draft', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Model created')])]
    public function store(CpqModelRequest $request): JsonResponse
    {
        $this->authorize('manage', CpqModel::class);

        return (new CpqRecordResource($this->models->create($request->payload())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}', operationId: 'showCpqModel', summary: 'Show a CPQ model with its definition', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Model')])]
    public function show(Request $request, string $id): CpqRecordResource
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('view', $model);

        abort_if($model->status !== CpqModel::Published && ! $request->user()?->can('manage', $model), Response::HTTP_NOT_FOUND);

        return new CpqRecordResource($model->load(['characteristics.options', 'rules', 'bomMappings', 'routingMappings', 'pricingFormulas']));
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/models/{id}', operationId: 'updateCpqModel', summary: 'Update a CPQ model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Model updated')])]
    public function update(CpqModelRequest $request, string $id): CpqRecordResource
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new CpqRecordResource($this->models->update($model, $request->payload()));
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/models/{id}', operationId: 'deleteCpqModel', summary: 'Delete a CPQ model without instances', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);
        $this->models->delete($model);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/publish', operationId: 'publishCpqModel', summary: 'Publish a CPQ model after the static check', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Published')])]
    public function publish(string $id): CpqRecordResource
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new CpqRecordResource($this->models->publish($model));
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/archive', operationId: 'archiveCpqModel', summary: 'Archive a CPQ model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Archived')])]
    public function archive(string $id): CpqRecordResource
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new CpqRecordResource($this->models->archive($model));
    }

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}/diagnostics', operationId: 'diagnoseCpqModel', summary: 'Static check of the expressions and references of a model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Issues')])]
    public function diagnostics(string $id): JsonResponse
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);
        $issues = $this->models->diagnostics($model);

        return response()->json(['data' => ['ok' => array_filter($issues, static fn (array $issue): bool => $issue['severity'] === 'error') === [], 'issues' => $issues]]);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/expression-check', operationId: 'checkCpqExpression', summary: 'Parse an expression and list its variables and the unknown ones', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Result')])]
    public function checkExpression(ExpressionCheckRequest $request, string $id): JsonResponse
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);
        $definition = $this->loader->load($model);
        $known = [...array_keys($definition['characteristics']), 'quantity', 'bom_cost', 'routing_cost', 'cost_subtotal', 'list_subtotal', 'attr', ...$this->diagnostics->derivedNames($definition)];

        return response()->json(['data' => $this->diagnostics->inspect((string) $request->validated('expression'), $known, $definition['characteristics'])]);
    }
}
