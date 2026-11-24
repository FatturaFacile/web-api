<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\CRM\Http\Requests\AssignAccountTerritoryRequest;
use Modules\CRM\Http\Requests\SaveSalesTerritoryRequest;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\AccountTerritory;
use Modules\CRM\Models\SalesTerritory;
use Modules\CRM\Services\SalesTerritoryService;
use OpenApi\Attributes as OA;

/**
 * Sales territories and the rule-based assignment of accounts.
 */
final class SalesTerritoryController extends CrmExtensionController
{
    public function __construct(private readonly SalesTerritoryService $territories) {}

    #[OA\Get(path: '/api/v1/crm/territories', operationId: 'listCrmSalesTerritories', description: 'The sales territories of the active company, paginated.', summary: 'List sales territories', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmSalesTerritory'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scoped(SalesTerritory::class)->with([]);

        return $this->paginated($query->orderBy('priority')->orderBy('code'));
    }

    #[OA\Post(path: '/api/v1/crm/territories', operationId: 'createCrmSalesTerritory', description: 'Create a sales territory.', summary: 'Create sales territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmSalesTerritoryRequest')), tags: ['CRM Territories & Quotas'], responses: [
        new OA\Response(response: 201, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesTerritory')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function store(SaveSalesTerritoryRequest $request): JsonResponse
    {
        return $this->createRecord(SalesTerritory::class, $request->validated());
    }

    #[OA\Get(path: '/api/v1/crm/territories/{id}', operationId: 'getCrmSalesTerritory', description: 'Return a sales territory.', summary: 'Show sales territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesTerritory')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return $this->present($this->find(SalesTerritory::class, $id));
    }

    #[OA\Put(path: '/api/v1/crm/territories/{id}', operationId: 'updateCrmSalesTerritory', description: 'Change a sales territory; only the fields sent are validated and changed.', summary: 'Update sales territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CrmSalesTerritoryRequest')), tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmSalesTerritory')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function update(SaveSalesTerritoryRequest $request, string $id): JsonResponse
    {
        return $this->updateRecord($this->find(SalesTerritory::class, $id, 'update'), $request->validated());
    }

    #[OA\Delete(path: '/api/v1/crm/territories/{id}', operationId: 'deleteCrmSalesTerritory', description: 'Delete a sales territory (soft delete).', summary: 'Delete sales territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 204, description: 'Deleted'),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        return $this->deleteRecord($this->find(SalesTerritory::class, $id, 'delete'));
    }

    #[OA\Get(path: '/api/v1/crm/territories/{id}/accounts', operationId: 'listCrmTerritoryAccounts', description: 'The accounts assigned to the territory, by rule or by hand.', summary: 'List accounts of a territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Records', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CrmAccountTerritory'))])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
    ])]
    public function accounts(string $id): AnonymousResourceCollection
    {
        $this->find(SalesTerritory::class, $id);

        return $this->paginated($this->scoped(AccountTerritory::class)->where('territory_id', $id)->with('account:id,name,account_number,industry,tier')->orderBy('assigned_at'));
    }

    #[OA\Post(path: '/api/v1/crm/territories/assign', operationId: 'assignCrmTerritories', description: 'Assigns every account of the company to the best matching active territory (lowest priority number). Manual assignments are kept. Returns the counts assigned, unmatched and kept manual.', summary: 'Run the territory rules', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], responses: [
        new OA\Response(response: 200, description: 'Result', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function assign(): JsonResponse
    {
        $this->authorize('create', SalesTerritory::class);

        return response()->json(['data' => $this->territories->assignAll($this->tenantId())]);
    }

    #[OA\Post(path: '/api/v1/crm/accounts/{id}/territory', operationId: 'assignCrmAccountTerritory', description: 'Pins the account to a territory by hand; later rule runs keep this assignment.', summary: 'Assign an account to a territory', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['CRM Territories & Quotas'], parameters: [new OA\Parameter(ref: '#/components/parameters/CrmExtId')], responses: [
        new OA\Response(response: 200, description: 'Record', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CrmAccountTerritory')])),
        new OA\Response(ref: '#/components/responses/CrmExtUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CrmExtForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CrmExtNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/CrmExtUnprocessable', response: 422),
    ])]
    public function assignAccount(AssignAccountTerritoryRequest $request, string $id): JsonResponse
    {
        $this->authorize('create', SalesTerritory::class);
        $account = $this->scoped(Account::class)->findOrFail($id);
        $territory = $this->find(SalesTerritory::class, (string) $request->validated('territory_id'));

        return $this->present($this->territories->assignManually($account, $territory));
    }
}
