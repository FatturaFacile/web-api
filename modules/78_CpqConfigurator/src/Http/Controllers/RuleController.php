<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Controllers\Concerns\ManagesModelChildren;
use Modules\CpqConfigurator\Http\Requests\RuleRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqConstraintRule;
use Modules\CpqConfigurator\Services\CpqModelService;
use OpenApi\Attributes as OA;

/**
 * Compatibility, inclusion, visibility, range and decision-table rules of a model.
 */
final class RuleController extends Controller
{
    use ManagesModelChildren;

    public function __construct(private readonly CpqModelService $models) {}

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}/rules', operationId: 'listCpqRules', summary: 'List the constraint rules of a model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Rules')])]
    public function index(string $id): AnonymousResourceCollection
    {
        return $this->listChildren(CpqConstraintRule::class, $id);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/rules', operationId: 'storeCpqRule', summary: 'Create a constraint rule', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Rule created')])]
    public function store(RuleRequest $request, string $id): JsonResponse
    {
        return $this->createChild($this->models, CpqConstraintRule::class, $id, $request->validated());
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/rules/{id}', operationId: 'updateCpqRule', summary: 'Update a constraint rule', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Rule updated')])]
    public function update(RuleRequest $request, string $id): CpqRecordResource
    {
        return $this->updateChild($this->models, CpqConstraintRule::class, $id, $request->validated());
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/rules/{id}', operationId: 'deleteCpqRule', summary: 'Delete a constraint rule', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        return $this->removeChild($this->models, CpqConstraintRule::class, $id);
    }
}
