<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CpqConfigurator\Http\Controllers\Concerns\ManagesModelChildren;
use Modules\CpqConfigurator\Http\Requests\PricingFormulaRequest;
use Modules\CpqConfigurator\Http\Resources\CpqRecordResource;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Services\CpqModelService;
use OpenApi\Attributes as OA;

/**
 * The pricing components of a model: cost and price additions, replacements, multipliers and discounts.
 */
final class PricingFormulaController extends Controller
{
    use ManagesModelChildren;

    public function __construct(private readonly CpqModelService $models) {}

    #[OA\Get(path: '/api/v1/cpq-configurator/models/{id}/pricing-formulas', operationId: 'listCpqPricingFormulas', summary: 'List the pricing formulas of a model', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Formulas')])]
    public function index(string $id): AnonymousResourceCollection
    {
        return $this->listChildren(CpqPricingFormula::class, $id);
    }

    #[OA\Post(path: '/api/v1/cpq-configurator/models/{id}/pricing-formulas', operationId: 'storeCpqPricingFormula', summary: 'Create a pricing formula', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 201, description: 'Formula created')])]
    public function store(PricingFormulaRequest $request, string $id): JsonResponse
    {
        return $this->createChild($this->models, CpqPricingFormula::class, $id, $request->validated());
    }

    #[OA\Put(path: '/api/v1/cpq-configurator/pricing-formulas/{id}', operationId: 'updateCpqPricingFormula', summary: 'Update a pricing formula', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 200, description: 'Formula updated')])]
    public function update(PricingFormulaRequest $request, string $id): CpqRecordResource
    {
        return $this->updateChild($this->models, CpqPricingFormula::class, $id, $request->validated());
    }

    #[OA\Delete(path: '/api/v1/cpq-configurator/pricing-formulas/{id}', operationId: 'deleteCpqPricingFormula', summary: 'Delete a pricing formula', security: [['bearerAuth' => []]], tags: ['CpqConfigurator'], responses: [new OA\Response(response: 204, description: 'Deleted')])]
    public function destroy(string $id): Response
    {
        return $this->removeChild($this->models, CpqPricingFormula::class, $id);
    }
}
