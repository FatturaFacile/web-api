<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Controllers\Concerns\ManagesModelChildren;
use Modules\CpqConfigurator\Http\Requests\BomMappingRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqSuperBomMapping;
use Modules\CpqConfigurator\Services\CpqModelService;
use OpenApi\Attributes as OA;

/**
 * The mappings of the dynamic super-BOM: which component enters the BOM, in which quantity and at which cost.
 */
final class BomMappingController extends Controller
{
    use ManagesModelChildren;

    public function __construct(private readonly CpqModelService $models) {}

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}/superbom-mappings', operationId: 'listCpqBomMappings', summary: 'List the super-BOM mappings of a model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Mappings')])]
    public function index(string $id): AnonymousResourceCollection
    {
        return $this->listChildren(CpqSuperBomMapping::class, $id);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/superbom-mappings', operationId: 'storeCpqBomMapping', summary: 'Create a super-BOM mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Mapping created')])]
    public function store(BomMappingRequest $request, string $id): JsonResponse
    {
        return $this->createChild($this->models, CpqSuperBomMapping::class, $id, $request->validated());
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/superbom-mappings/{id}', operationId: 'updateCpqBomMapping', summary: 'Update a super-BOM mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Mapping updated')])]
    public function update(BomMappingRequest $request, string $id): CpqRecordResource
    {
        return $this->updateChild($this->models, CpqSuperBomMapping::class, $id, $request->validated());
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/superbom-mappings/{id}', operationId: 'deleteCpqBomMapping', summary: 'Delete a super-BOM mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        return $this->removeChild($this->models, CpqSuperBomMapping::class, $id);
    }
}
