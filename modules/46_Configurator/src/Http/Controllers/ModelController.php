<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Configurator\Http\Requests\ConfigurationRequest;
use Modules\Configurator\Http\Requests\ModelRequest;
use Modules\Configurator\Http\Resources\ConfigurableModelResource;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Services\ConfigurableModelService;
use Modules\Configurator\Services\ConfigurationService;
use OpenApi\Attributes as OA;

/**
 * The configurable models: definition, publication, rule check and the live evaluation of a selection.
 */
final class ModelController extends Controller
{
    public function __construct(private readonly ConfigurableModelService $models, private readonly ConfigurationService $configurations) {}

    /**
     * List models.
     */
    #[OA\Get(path: '/api/v1/configurator/models', operationId: 'listConfiguratorModels', description: 'Return the configurable models of the company, newest first. Optional filter by status.', summary: 'List configurable models', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'published', 'archived'])),
    ], responses: [
        new OA\Response(response: 200, description: 'Models', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelCollection')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ConfigurableModel::class);

        $models = ConfigurableModel::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return ConfigurableModelResource::collection($models);
    }

    /**
     * Create model.
     */
    #[OA\Post(path: '/api/v1/configurator/models', operationId: 'storeConfiguratorModel', description: 'Create a configurable model as a draft. Add its groups, options and rules, then publish it.', summary: 'Create configurable model', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelRequest')), tags: ['Configurator'], responses: [
        new OA\Response(response: 201, description: 'Model created', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function store(ModelRequest $request): JsonResponse
    {
        $this->authorize('manage', ConfigurableModel::class);

        return (new ConfigurableModelResource($this->models->create($request->payload())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Show model.
     */
    #[OA\Get(path: '/api/v1/configurator/models/{id}', operationId: 'showConfiguratorModel', description: 'Return a model with its option groups, options and rules.', summary: 'Show configurable model', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Model', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
    ])]
    public function show(string $id): ConfigurableModelResource
    {
        $model = ConfigurableModel::query()->with(['groups.options', 'rules'])->findOrFail($id);
        $this->authorize('view', $model);

        return new ConfigurableModelResource($model);
    }

    /**
     * Update model.
     */
    #[OA\Put(path: '/api/v1/configurator/models/{id}', operationId: 'updateConfiguratorModel', description: 'Change the header, price, cost, tax rate, minimum margin or quantity breaks of a model. A change of price data on a published model raises its version. An archived model cannot be changed.', summary: 'Update configurable model', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Model updated', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function update(ModelRequest $request, string $id): ConfigurableModelResource
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new ConfigurableModelResource($this->models->update($model, $request->payload()));
    }

    /**
     * Delete model.
     */
    #[OA\Delete(path: '/api/v1/configurator/models/{id}', operationId: 'deleteConfiguratorModel', description: 'Delete a model with its groups, options and rules. Refused when configurations were made with it: archive it instead.', summary: 'Delete configurable model', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 204, description: 'Model deleted'),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);
        $this->models->delete($model);

        return response()->noContent();
    }

    /**
     * Publish model.
     */
    #[OA\Post(path: '/api/v1/configurator/models/{id}/publish', operationId: 'publishConfiguratorModel', description: 'Publish a draft model so it can be configured. Refused while a group has no active option, a range has no bounds, or the rules contain a cycle or a conflict.', summary: 'Publish configurable model', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Model published', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function publish(string $id): ConfigurableModelResource
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new ConfigurableModelResource($this->models->publish($model)->load(['groups.options', 'rules']));
    }

    /**
     * Archive model.
     */
    #[OA\Post(path: '/api/v1/configurator/models/{id}/archive', operationId: 'archiveConfiguratorModel', description: 'Withdraw a model from sale. Existing configurations and quotes are untouched; the model can no longer be changed or configured.', summary: 'Archive configurable model', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Model archived', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorModelResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function archive(string $id): ConfigurableModelResource
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return new ConfigurableModelResource($this->models->archive($model));
    }

    /**
     * Check rules.
     */
    #[OA\Get(path: '/api/v1/configurator/models/{id}/rule-check', operationId: 'checkConfiguratorRules', description: 'Analyse the active rules of the model and list the problems: unknown options, requires cycles, rules that require and exclude the same option, defaults that are excluded, two options of one single choice required together.', summary: 'Check the rules of a model', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Rule check', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleCheckResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
    ])]
    public function ruleCheck(string $id): JsonResponse
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('view', $model);
        $problems = $this->models->ruleProblems($model);

        return response()->json(['data' => ['valid' => $problems === [], 'problems' => $problems]]);
    }

    /**
     * Evaluate a selection.
     */
    #[OA\Post(path: '/api/v1/configurator/models/{id}/evaluate', operationId: 'evaluateConfiguratorSelection', description: 'Validate a selection against the model and price it without saving anything: the violations of the structure and of the rules, the defaults that were applied, the resolved selections and the price (list price, discounts, totals, cost and margin with the margin guard). Works on draft models too, to test them.', summary: 'Evaluate a selection', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorConfigurationRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Evaluation', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorEvaluationResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function evaluate(ConfigurationRequest $request, string $id): JsonResponse
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('view', $model);

        return response()->json(['data' => $this->configurations->evaluate($model, $request->payload())]);
    }
}
