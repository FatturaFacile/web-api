<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Http\Resources\CatalogResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared CRUD behaviour for simple tenant-scoped catalog lookups.
 * Using controllers must define `modelClass()` and `label()`.
 */
trait HandlesLookupCrud
{
    use ApiResponse;

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    abstract protected function label(): string;

    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $records = ($this->modelClass())::query()->latest()->paginate($perPage);

        return $this->successResponse(
            data: CatalogResource::collection($records),
            message: __('api.list_retrieved', ['resource' => $this->label()]),
            meta: ['current_page' => $records->currentPage(), 'last_page' => $records->lastPage(), 'per_page' => $records->perPage(), 'total' => $records->total()]
        );
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse(new CatalogResource(($this->modelClass())::findOrFail($id)), __('api.retrieved', ['resource' => $this->label()]));
    }

    public function destroy(string $id): JsonResponse
    {
        ($this->modelClass())::findOrFail($id)->delete();

        return $this->successResponse(null, __('api.deleted', ['resource' => $this->label()]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createRecord(array $data): JsonResponse
    {
        $record = ($this->modelClass())::create($data);

        return $this->successResponse(new CatalogResource($record), __('api.created', ['resource' => $this->label()]), Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function updateRecord(string $id, array $data): JsonResponse
    {
        $record = ($this->modelClass())::findOrFail($id);
        $record->update($data);

        return $this->successResponse(new CatalogResource($record), __('api.updated', ['resource' => $this->label()]));
    }
}
