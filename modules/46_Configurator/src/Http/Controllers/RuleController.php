<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Configurator\Http\Requests\RuleRequest;
use Modules\Configurator\Http\Resources\ConfiguratorRuleResource;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorRule;
use Modules\Configurator\Services\ConfigurableModelService;
use OpenApi\Attributes as OA;

/**
 * The rules over the selected options: requires, excludes, default and conditional price rules.
 */
final class RuleController extends Controller
{
    public function __construct(private readonly ConfigurableModelService $models) {}

    /**
     * List rules.
     */
    #[OA\Get(path: '/api/v1/configurator/models/{id}/rules', operationId: 'listConfiguratorRules', description: 'Return the rules of a model by priority.', summary: 'List rules', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleCollection')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
    ])]
    public function index(string $id): AnonymousResourceCollection
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('view', $model);

        return ConfiguratorRuleResource::collection($model->rules()->get());
    }

    /**
     * Create rule.
     */
    #[OA\Post(path: '/api/v1/configurator/models/{id}/rules', operationId: 'storeConfiguratorRule', description: 'Add a rule. It is analysed together with the other active rules and refused when it creates a requires cycle, requires and excludes the same option, defaults an excluded option or points at an unknown option.', summary: 'Create rule', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function store(RuleRequest $request, string $id): JsonResponse
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return (new ConfiguratorRuleResource($this->models->saveRule($model, $request->payload())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update rule.
     */
    #[OA\Put(path: '/api/v1/configurator/rules/{id}', operationId: 'updateConfiguratorRule', description: 'Change a rule. The changed rule set is analysed again and a change that creates a cycle or a conflict is refused.', summary: 'Update rule', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorRuleResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function update(RuleRequest $request, string $id): ConfiguratorRuleResource
    {
        $rule = ConfiguratorRule::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($rule->model_id);
        $this->authorize('manage', $model);

        return new ConfiguratorRuleResource($this->models->saveRule($model, $request->payload($rule->type), $rule));
    }

    /**
     * Delete rule.
     */
    #[OA\Delete(path: '/api/v1/configurator/rules/{id}', operationId: 'deleteConfiguratorRule', description: 'Delete a rule.', summary: 'Delete rule', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 204, description: 'Rule deleted'),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $rule = ConfiguratorRule::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($rule->model_id);
        $this->authorize('manage', $model);
        $this->models->deleteRule($model, $rule);

        return response()->noContent();
    }
}
