<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\SaveSalesQuotaRequest;
use Modules\CRM\Http\Resources\CrmRecordResource;
use Modules\CRM\Models\SalesQuota;
use Modules\CRM\Services\SalesQuotaService;
use OpenApi\Attributes as OA;

/**
 * Sales quotas of users and territories with attainment.
 */
final class SalesQuotaController extends CrmExtensionController
{
    public function __construct(private readonly SalesQuotaService $quotas) {}

    #[OA\Get(path: '/api/v1/crm/sales-quotas', operationId: 'listCrmSalesQuotas', description: 'Sales quotas with their attainment (won opportunities of the period). Filters: assignee_id (the sales rep), territory_id.', summary: 'List sales quotas', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmSalesQuota'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(['assignee_id' => ['nullable', 'uuid'], 'territory_id' => ['nullable', 'uuid']]);
        $this->authorize('viewAny', SalesQuota::class);
        $page = $this->scoped(SalesQuota::class)
            ->when(isset($filters['assignee_id']), static fn ($query) => $query->where('user_id', $filters['assignee_id']))
            ->when(isset($filters['territory_id']), static fn ($query) => $query->where('territory_id', $filters['territory_id']))
            ->orderByDesc('period_start')
            ->paginate($this->perPage());
        $page->getCollection()->each(fn (SalesQuota $quota) => $quota->setAttribute('attainment', $this->quotas->attainment($quota)));

        return CrmRecordResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/crm/sales-quotas', operationId: 'createCrmSalesQuota', description: 'Create a sales quota.', summary: 'Create sales quota', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmSalesQuotaRequest')), tags: ['CRM Territories & Quotas'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesQuota')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveSalesQuotaRequest $request): JsonResponse
    {
        return $this->createRecord(SalesQuota::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/sales-quotas/{id}', operationId: 'getCrmSalesQuota', description: 'Return a sales quota.', summary: 'Show sales quota', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesQuota')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        $quota = $this->find(SalesQuota::class, $id);
        $quota->setAttribute('attainment', $this->quotas->attainment($quota));

        return $this->present($quota);
    }

    #[OA\Put(path: '/api/v1/crm/sales-quotas/{id}', operationId: 'updateCrmSalesQuota', description: 'Change a sales quota; only the fields sent are validated and changed.', summary: 'Update sales quota', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmSalesQuotaRequest')), tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesQuota')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SaveSalesQuotaRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(SalesQuota::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/sales-quotas/{id}', operationId: 'deleteCrmSalesQuota', description: 'Delete a sales quota (soft delete).', summary: 'Delete sales quota', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(SalesQuota::class, $id, 'delete'));
    }
}
