<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqModel;
use Modules\CpqConfigurator\Services\CpqModelService;

/**
 * The four writes every definition child of a model has (rules, BOM mappings, routing mappings, pricing formulas):
 * reads belong to the members of the company who may see the model, writes to who may manage the configurator.
 */
trait ManagesModelChildren
{
    /**
     * @param  class-string<Model>  $class
     */
    private function listChildren(string $class, string $modelId): AnonymousResourceCollection
    {
        $model = CpqModel::query()->findOrFail($modelId);
        $this->authorize('manage', $model);

        return CpqRecordResource::collection($class::query()->where('model_id', $model->id)->get());
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $data
     */
    private function createChild(CpqModelService $service, string $class, string $modelId, array $data): JsonResponse
    {
        $model = CpqModel::query()->findOrFail($modelId);
        $this->authorize('manage', $model);

        return (new CpqRecordResource($service->saveChild($model, $class, $data)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $data
     */
    private function updateChild(CpqModelService $service, string $class, string $id, array $data): CpqRecordResource
    {
        $child = $class::query()->findOrFail($id);
        $model = CpqModel::query()->findOrFail($child->getAttribute('model_id'));
        $this->authorize('manage', $model);

        return new CpqRecordResource($service->saveChild($model, $class, $data, $child));
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function removeChild(CpqModelService $service, string $class, string $id): Response
    {
        $child = $class::query()->findOrFail($id);
        $this->authorize('manage', CpqModel::query()->findOrFail($child->getAttribute('model_id')));
        $service->deleteChild($child);

        return response()->noContent();
    }
}
