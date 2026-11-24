<?php

declare(strict_types=1);

namespace Modules\Configurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\Configurator\Http\Requests\OptionRequest;
use Modules\Configurator\Http\Resources\ConfiguratorOptionResource;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Services\ConfigurableModelService;
use OpenApi\Attributes as OA;

/**
 * The options of a group: the choices, with their price delta or multiplier, cost and optional product.
 */
final class OptionController extends Controller
{
    public function __construct(private readonly ConfigurableModelService $models) {}

    /**
     * Create option.
     */
    #[OA\Post(path: '/api/v1/configurator/groups/{id}/options', operationId: 'storeConfiguratorOption', description: 'Add an option to a single, multiple or boolean group. A boolean group owns exactly one option, a range group has none. Marking an option as default un-marks the others of a single-choice group.', summary: 'Create option', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorOptionRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 201, description: 'Option created', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorOptionResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function store(OptionRequest $request, string $id): JsonResponse
    {
        $group = ConfiguratorOptionGroup::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($group->model_id);
        $this->authorize('manage', $model);

        return (new ConfiguratorOptionResource($this->models->saveOption($model, $group, $request->payload())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update option.
     */
    #[OA\Put(path: '/api/v1/configurator/options/{id}', operationId: 'updateConfiguratorOption', description: 'Change an option: its prices, cost, product, default flag or whether it is still offered.', summary: 'Update option', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorOptionRequest')), tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 200, description: 'Option updated', content: new OA\JsonContent(ref: '#/components/schemas/ConfiguratorOptionResponse')),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function update(OptionRequest $request, string $id): ConfiguratorOptionResource
    {
        $option = ConfiguratorOption::query()->findOrFail($id);
        $group = ConfiguratorOptionGroup::query()->findOrFail($option->group_id);
        $model = ConfigurableModel::query()->findOrFail($option->model_id);
        $this->authorize('manage', $model);

        return new ConfiguratorOptionResource($this->models->saveOption($model, $group, $request->payload(), $option));
    }

    /**
     * Delete option.
     */
    #[OA\Delete(path: '/api/v1/configurator/options/{id}', operationId: 'deleteConfiguratorOption', description: 'Delete an option. Refused while a rule uses it; switch it off instead to stop offering it.', summary: 'Delete option', security: [['bearerAuth' => []]], tags: ['Configurator'], parameters: [new OA\Parameter(ref: '#/components/parameters/ConfiguratorId')], responses: [
        new OA\Response(response: 204, description: 'Option deleted'),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/ConfiguratorForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/ConfiguratorNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/ConfiguratorUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $option = ConfiguratorOption::query()->findOrFail($id);
        $model = ConfigurableModel::query()->findOrFail($option->model_id);
        $this->authorize('manage', $model);
        $this->models->deleteOption($model, $option);

        return response()->noContent();
    }
}
