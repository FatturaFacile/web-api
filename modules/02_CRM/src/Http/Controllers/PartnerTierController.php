<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SavePartnerTierRequest;
use Modules\CRM\Models\PartnerTier;
use OpenApi\Attributes as OA;

/**
 * Partner program tiers.
 */
final class PartnerTierController extends CrmExtensionController
{
    #[OA\Get(path: '/api/v1/crm/partner-tiers', operationId: 'listCrmPartnerTiers', description: 'The partner tiers of the active company, paginated.', summary: 'List partner tiers', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmPartnerTier'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scoped(PartnerTier::class)->with([]);

        return $this->paginated($query->orderBy('rank'));
    }

    #[OA\Post(path: '/api/v1/crm/partner-tiers', operationId: 'createCrmPartnerTier', description: 'Create a partner tier.', summary: 'Create partner tier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPartnerTierRequest')), tags: ['CRM Partners'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartnerTier')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SavePartnerTierRequest $request): JsonResponse
    {
        return $this->createRecord(PartnerTier::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/partner-tiers/{id}', operationId: 'getCrmPartnerTier', description: 'Return a partner tier.', summary: 'Show partner tier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartnerTier')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(PartnerTier::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/partner-tiers/{id}', operationId: 'updateCrmPartnerTier', description: 'Change a partner tier; only the fields sent are validated and changed.', summary: 'Update partner tier', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPartnerTierRequest')), tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartnerTier')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SavePartnerTierRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(PartnerTier::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/partner-tiers/{id}', operationId: 'deleteCrmPartnerTier', description: 'Delete a partner tier (soft delete).', summary: 'Delete partner tier', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(PartnerTier::class, $id, 'delete'));
    }
}
