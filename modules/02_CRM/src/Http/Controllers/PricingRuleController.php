<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SavePricingRuleRequest;
use Modules\CRM\Models\PricingRule;
use OpenApi\Attributes as OA;

/**
 * CPQ pricing rules.
 */
final class PricingRuleController extends CrmExtensionController
{
    #[OA\Get(path: '/api/v1/crm/pricing-rules', operationId: 'listCrmPricingRules', description: 'The pricing rules of the active company, paginated.', summary: 'List pricing rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmPricingRule'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scoped(PricingRule::class)->with([]);

        return $this->paginated($query->orderBy('priority')->orderByDesc('discount_percent'));
    }

    #[OA\Post(path: '/api/v1/crm/pricing-rules', operationId: 'createCrmPricingRule', description: 'Create a pricing rule.', summary: 'Create pricing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPricingRuleRequest')), tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPricingRule')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SavePricingRuleRequest $request): JsonResponse
    {
        return $this->createRecord(PricingRule::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/pricing-rules/{id}', operationId: 'getCrmPricingRule', description: 'Return a pricing rule.', summary: 'Show pricing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPricingRule')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(PricingRule::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/pricing-rules/{id}', operationId: 'updateCrmPricingRule', description: 'Change a pricing rule; only the fields sent are validated and changed.', summary: 'Update pricing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPricingRuleRequest')), tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPricingRule')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SavePricingRuleRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(PricingRule::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/pricing-rules/{id}', operationId: 'deleteCrmPricingRule', description: 'Delete a pricing rule (soft delete).', summary: 'Delete pricing rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(PricingRule::class, $id, 'delete'));
    }
}
