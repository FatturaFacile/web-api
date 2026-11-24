<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Configurator\Http\Requests\OptionGroupRequest;
use Modules\Configurator\Http\Resources\ConfiguratorOptionGroupResource;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Services\ConfigurableModelService;
use OpenApi\Attributes as OA;

/**
 * The option groups of a model: the questions of the configuration.
 */
final class GroupController extends Controller
{
    public function __construct(private readonly ConfigurableModelService $models) {}

    /**
     * Create group.
     */
    #[OA\Post(path: '/api/v1/configurator/models/{id}/groups', operationId: 'storeConfiguratorGroup', description: 'Add an option group to a model: a single choice, a multiple choice, a boolean feature or a numeric range with a price per unit.', summary: 'Create option group', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorGroupRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 201, description: 'Group created', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorGroupResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function store(OptionGroupRequest $request, string $id): JsonResponse
    {
        $model = ConfigurableModel::query()->findOrFail($id);
        $this->authorize('manage', $model);
        $group = $this->models->saveGroup($model, $request->validated());

        return (new ConfiguratorOptionGroupResource($group->load('options')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update group.
     */
    #[OA\Put(path: '/api/v1/configurator/groups/{id}', operationId: 'updateConfiguratorGroup', description: 'Change an option group. A group with options cannot become a range, and a boolean group owns exactly one option.', summary: 'Update option group', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorGroupRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Group updated', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorGroupResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function update(OptionGroupRequest $request, string $id): ConfiguratorOptionGroupResource
    {
        $group = ConfiguratorOptionGroup::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($group->model_id);
        $this->authorize('manage', $model);

        return new ConfiguratorOptionGroupResource($this->models->saveGroup($model, $request->validated(), $group)->load('options'));
    }

    /**
     * Delete group.
     */
    #[OA\Delete(path: '/api/v1/configurator/groups/{id}', operationId: 'deleteConfiguratorGroup', description: 'Delete an option group with its options. Refused while a rule uses the group or one of its options.', summary: 'Delete option group', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 204, description: 'Group deleted'),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $group = ConfiguratorOptionGroup::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($group->model_id);
        $this->authorize('manage', $model);
        $this->models->deleteGroup($model, $group);

        return response()->noContent();
    }
}
