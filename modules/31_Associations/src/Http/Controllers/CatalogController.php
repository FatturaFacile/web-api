<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\SaveCatalogEntryRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\CatalogRegistry;

/**
 * The configuration records of the module: years, types, roles, fee plans, disciplines, resources, areas and the rest.
 */
final class CatalogController extends AssociationController
{
    public function __construct(private readonly CatalogRegistry $catalogs) {}

    #[AssociationGet('/api/v1/associations/catalogs/{catalog}', 'listAssociationCatalog', 'List the entries of a catalog')]
    public function index(Request $request, string $catalog): AnonymousResourceCollection
    {
        $this->allow($this->catalogs->readCapability($catalog));
        $model = $this->catalogs->model($catalog);

        return AssociationRecordResource::collection($model::query()->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/catalogs/{catalog}', 'createAssociationCatalogEntry', 'Create an entry of a catalog', 'AssociationCatalogEntryRequest')]
    public function store(SaveCatalogEntryRequest $request, string $catalog): AssociationRecordResource
    {
        $this->allow($this->catalogs->writeCapability($catalog));
        $model = $this->catalogs->model($catalog);

        return new AssociationRecordResource($model::create($request->validated()));
    }

    #[AssociationGet('/api/v1/associations/catalogs/{catalog}/{id}', 'showAssociationCatalogEntry', 'Show an entry of a catalog')]
    public function show(string $catalog, string $id): AssociationRecordResource
    {
        $this->allow($this->catalogs->readCapability($catalog));
        $model = $this->catalogs->model($catalog);

        return new AssociationRecordResource($model::query()->findOrFail($id));
    }

    #[AssociationPut('/api/v1/associations/catalogs/{catalog}/{id}', 'updateAssociationCatalogEntry', 'Update an entry of a catalog. Generic entries are read-only', 'AssociationCatalogEntryRequest')]
    public function update(SaveCatalogEntryRequest $request, string $catalog, string $id): AssociationRecordResource
    {
        $this->allow($this->catalogs->writeCapability($catalog));
        $model = $this->catalogs->model($catalog);
        $entry = $model::query()->findOrFail($id);
        $entry->update($request->validated());

        return new AssociationRecordResource($entry);
    }

    #[AssociationDelete('/api/v1/associations/catalogs/{catalog}/{id}', 'deleteAssociationCatalogEntry', 'Delete an entry of a catalog. Generic entries are read-only')]
    public function destroy(string $catalog, string $id): JsonResponse
    {
        $this->allow($this->catalogs->writeCapability($catalog));
        $model = $this->catalogs->model($catalog);
        $model::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
