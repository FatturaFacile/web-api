<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Requests\CharacteristicRequest;
use Modules\CpqConfigurator\Http\Requests\OptionRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Services\CpqModelService;
use OpenApi\Attributes as OA;

/**
 * Characteristics of a model (value list, numeric range, boolean) and the options of the value lists.
 */
final class CharacteristicController extends Controller
{
    public function __construct(private readonly CpqModelService $models) {}

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/characteristics', operationId: 'storeCpqCharacteristic', summary: 'Create a characteristic', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Characteristic created')])]
    public function store(CharacteristicRequest $request, string $id): JsonResponse
    {
        $model = CpqModel::query()->findOrFail($id);
        $this->authorize('manage', $model);

        return (new CpqRecordResource($this->models->saveCharacteristic($model, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/characteristics/{id}', operationId: 'updateCpqCharacteristic', summary: 'Update a characteristic', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Characteristic updated')])]
    public function update(CharacteristicRequest $request, string $id): CpqRecordResource
    {
        $characteristic = CpqCharacteristic::query()->findOrFail($id);
        $model = CpqModel::query()->findOrFail($characteristic->model_id);
        $this->authorize('manage', $model);

        return new CpqRecordResource($this->models->saveCharacteristic($model, $request->validated(), $characteristic));
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/characteristics/{id}', operationId: 'deleteCpqCharacteristic', summary: 'Delete a characteristic with its options', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        $characteristic = CpqCharacteristic::query()->findOrFail($id);
        $this->authorize('manage', CpqModel::query()->findOrFail($characteristic->model_id));
        $this->models->deleteChild($characteristic);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/characteristics/{id}/options', operationId: 'storeCpqOption', summary: 'Create an option of a value-list characteristic', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Option created')])]
    public function storeOption(OptionRequest $request, string $id): JsonResponse
    {
        $characteristic = CpqCharacteristic::query()->findOrFail($id);
        $this->authorize('manage', CpqModel::query()->findOrFail($characteristic->model_id));

        return (new CpqRecordResource($this->models->saveOption($characteristic, $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/options/{id}', operationId: 'updateCpqOption', summary: 'Update an option', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Option updated')])]
    public function updateOption(OptionRequest $request, string $id): CpqRecordResource
    {
        $option = CpqCharacteristicOption::query()->findOrFail($id);
        $characteristic = CpqCharacteristic::query()->findOrFail($option->characteristic_id);
        $this->authorize('manage', CpqModel::query()->findOrFail($characteristic->model_id));

        return new CpqRecordResource($this->models->saveOption($characteristic, $request->validated(), $option));
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/options/{id}', operationId: 'deleteCpqOption', summary: 'Delete an option', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroyOption(string $id): Response
    {
        $option = CpqCharacteristicOption::query()->findOrFail($id);
        $this->authorize('manage', CpqModel::query()->findOrFail($option->model_id));
        $this->models->deleteChild($option);

        return response()->noContent();
    }
}
