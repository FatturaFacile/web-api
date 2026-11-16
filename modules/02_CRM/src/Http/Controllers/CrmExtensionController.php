<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Controllers\Concerns\InteractsWithCrmExtension;
use Modules\CRM\Http\Resources\CrmRecordResource;

/**
 * Shared tenant-scoped CRUD of the CRM extension controllers: every query is limited to the active company and every
 * record goes through the policy before it is read or changed.
 */
abstract class CrmExtensionController extends Controller
{
    use InteractsWithCrmExtension;

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    protected function scoped(string $model): Builder
    {
        return $model::query()->where('tenant_id', $this->tenantId());
    }

    /**
     * @param  Builder<Model>  $query
     */
    protected function paginated(Builder $query): AnonymousResourceCollection
    {
        $this->authorize('viewAny', $query->getModel()::class);

        return CrmRecordResource::collection($query->paginate($this->perPage()));
    }

    protected function present(Model $record, int $status = 200): JsonResponse
    {
        return (new CrmRecordResource($record))->response()->setStatusCode($status);
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $with
     */
    protected function find(string $model, string $id, string $ability = 'view', array $with = []): Model
    {
        $record = $this->scoped($model)->with($with)->findOrFail($id);
        $this->authorize($ability, $record);

        return $record;
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $data
     */
    protected function createRecord(string $model, array $data): JsonResponse
    {
        $this->authorize('create', $model);

        return $this->present($model::create(['tenant_id' => $this->tenantId(), ...$data]), Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function updateRecord(Model $record, array $data): JsonResponse
    {
        $this->authorize('update', $record);
        $record->fill($data)->save();

        return $this->present($record->refresh());
    }

    protected function deleteRecord(Model $record): Response
    {
        $this->authorize('delete', $record);
        $record->delete();

        return response()->noContent();
    }
}
