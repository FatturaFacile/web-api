<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SavePartnerRequest;
use Modules\CRM\Models\Partner;
use Modules\CRM\Services\PartnerProgramService;
use OpenApi\Attributes as OA;

/**
 * Channel partners.
 */
final class PartnerController extends CrmExtensionController
{
    public function __construct(private readonly PartnerProgramService $program) {}

    #[OA\Get(path: '/api/v1/crm/partners', operationId: 'listCrmPartners', description: 'The partners of the active company with their tier. Filter: status.', summary: 'List partners', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmPartner'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scoped(Partner::class)->with(['tier']);
        $status = $request->validate(['status' => ['nullable', 'string']])['status'] ?? null;
        $query->when($status !== null, static fn ($inner) => $inner->where('status', $status));

        return $this->paginated($query->orderBy('name'));
    }

    #[OA\Post(path: '/api/v1/crm/partners', operationId: 'createCrmPartner', description: 'Create a partner.', summary: 'Create partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPartnerRequest')), tags: ['CRM Partners'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartner')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SavePartnerRequest $request): JsonResponse
    {
        return $this->createRecord(Partner::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/partners/{id}', operationId: 'getCrmPartner', description: 'Return a partner.', summary: 'Show partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartner')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(Partner::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/partners/{id}', operationId: 'updateCrmPartner', description: 'Change a partner; only the fields sent are validated and changed.', summary: 'Update partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmPartnerRequest')), tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartner')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SavePartnerRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(Partner::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/partners/{id}', operationId: 'deleteCrmPartner', description: 'Delete a partner (soft delete).', summary: 'Delete partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(Partner::class, $id, 'delete'));
    }

    #[OA\Post(path: '/api/v1/crm/partners/{id}/evaluate-tier', operationId: 'evaluateCrmPartnerTier', description: 'Recomputes the closed revenue of the last 12 months (won deal registrations) and moves the partner to the highest tier whose minimum is reached.', summary: 'Evaluate the tier of a partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmPartner')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function evaluateTier(string $id): JsonResponse
    {
        $partner = $this->find(Partner::class, $id, 'update');
        $this->program->evaluateTier($partner);

        return $this->present($partner->load('tier'));
    }

    #[OA\Post(path: '/api/v1/crm/partners/evaluate-tiers', operationId: 'evaluateCrmPartnerTiers', description: 'Runs the tier evaluation for the active partners; returns how many changed tier.', summary: 'Evaluate the tier of every partner', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Partners'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function evaluateTiers(): JsonResponse
    {
        $this->authorize('create', Partner::class);

        return response()->json(['data' => ['changed' => $this->program->evaluateAll($this->tenantId())]]);
    }
}
