<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Controllers\Concerns\ManagesModelChildren;
use Modules\CpqConfigurator\Http\Requests\RoutingMappingRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqRoutingMapping;
use Modules\CpqConfigurator\Services\CpqModelService;
use OpenApi\Attributes as OA;

/**
 * The mappings of the dynamic routing: operations, work centres and the hours they take.
 */
final class RoutingMappingController extends Controller
{
    use ManagesModelChildren;

    public function __construct(private readonly CpqModelService $models) {}

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}/routing-mappings', operationId: 'listCpqRoutingMappings', summary: 'List the routing mappings of a model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Mappings')])]
    public function index(string $id): AnonymousResourceCollection
    {
        return $this->listChildren(CpqRoutingMapping::class, $id);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/routing-mappings', operationId: 'storeCpqRoutingMapping', summary: 'Create a routing mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Mapping created')])]
    public function store(RoutingMappingRequest $request, string $id): JsonResponse
    {
        return $this->createChild($this->models, CpqRoutingMapping::class, $id, $request->validated());
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/routing-mappings/{id}', operationId: 'updateCpqRoutingMapping', summary: 'Update a routing mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Mapping updated')])]
    public function update(RoutingMappingRequest $request, string $id): CpqRecordResource
    {
        return $this->updateChild($this->models, CpqRoutingMapping::class, $id, $request->validated());
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/routing-mappings/{id}', operationId: 'deleteCpqRoutingMapping', summary: 'Delete a routing mapping', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        return $this->removeChild($this->models, CpqRoutingMapping::class, $id);
    }
}
