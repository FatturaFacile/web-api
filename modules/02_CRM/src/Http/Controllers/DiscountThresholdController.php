<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SaveDiscountThresholdRequest;
use Modules\CRM\Models\DiscountThreshold;
use OpenApi\Attributes as OA;

/**
 * Discount approval thresholds.
 */
final class DiscountThresholdController extends CrmExtensionController
{
    #[OA\Get(path: '/api/v1/crm/discount-thresholds', operationId: 'listCrmDiscountThresholds', description: 'The discount thresholds of the active company, paginated.', summary: 'List discount thresholds', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmDiscountThreshold'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scoped(DiscountThreshold::class)->with([]);

        return $this->paginated($query->orderBy('from_discount_percent'));
    }

    #[OA\Post(path: '/api/v1/crm/discount-thresholds', operationId: 'createCrmDiscountThreshold', description: 'Create a discount threshold.', summary: 'Create discount threshold', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDiscountThresholdRequest')), tags: ['CRM CPQ'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDiscountThreshold')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveDiscountThresholdRequest $request): JsonResponse
    {
        return $this->createRecord(DiscountThreshold::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/discount-thresholds/{id}', operationId: 'getCrmDiscountThreshold', description: 'Return a discount threshold.', summary: 'Show discount threshold', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDiscountThreshold')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(DiscountThreshold::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/discount-thresholds/{id}', operationId: 'updateCrmDiscountThreshold', description: 'Change a discount threshold; only the fields sent are validated and changed.', summary: 'Update discount threshold', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmDiscountThresholdRequest')), tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmDiscountThreshold')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SaveDiscountThresholdRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(DiscountThreshold::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/discount-thresholds/{id}', operationId: 'deleteCrmDiscountThreshold', description: 'Delete a discount threshold (soft delete).', summary: 'Delete discount threshold', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM CPQ'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(DiscountThreshold::class, $id, 'delete'));
    }
}
