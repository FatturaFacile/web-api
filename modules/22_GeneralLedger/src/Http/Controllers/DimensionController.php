<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\GeneralLedger\Http\Requests\StoreDimensionRequest;
use Modules\GeneralLedger\Models\CostCenter;
use Modules\GeneralLedger\Models\ProfitCenter;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cost centres and profit centres, the analysis dimensions of the journal lines. A centre already used by postings is
 * deactivated, never deleted.
 */
final class DimensionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Get(path: '/api/v1/general-ledger/cost-centers', operationId: 'listGeneralLedgerCostCenters', description: 'Cost centres of the tenant by code; `active=true` lists only the active ones.', summary: 'List cost centres', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        parameters: [new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [
            new OA\Response(response: 200, description: 'Cost centres', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerDimensionResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ])]
    public function indexCostCenters(Request $request): JsonResponse
    {
        return $this->list(CostCenter::query(), $request);
    }

    #[OA\Post(path: '/api/v1/general-ledger/cost-centers', operationId: 'createGeneralLedgerCostCenter', description: 'Creates a cost centre; the code is unique in the tenant.', summary: 'Create cost centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreDimensionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Cost centre created', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerDimensionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or code already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ])]
    public function storeCostCenter(StoreDimensionRequest $request): JsonResponse
    {
        return $this->create(new CostCenter, $request);
    }

    #[OA\Put(path: '/api/v1/general-ledger/cost-centers/{id}', operationId: 'updateGeneralLedgerCostCenter', description: 'Changes code, name or active flag of a cost centre. Postings already made keep it.', summary: 'Update cost centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreDimensionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Cost centre updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerDimensionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or code already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ])]
    public function updateCostCenter(StoreDimensionRequest $request, string $id): JsonResponse
    {
        return $this->change(CostCenter::findOrFail($id), $request);
    }

    #[OA\Get(path: '/api/v1/general-ledger/profit-centers', operationId: 'listGeneralLedgerProfitCenters', description: 'Profit centres of the tenant by code; `active=true` lists only the active ones.', summary: 'List profit centres', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        parameters: [new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [
            new OA\Response(response: 200, description: 'Profit centres', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GeneralLedgerDimensionResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'No ledger read access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ])]
    public function indexProfitCenters(Request $request): JsonResponse
    {
        return $this->list(ProfitCenter::query(), $request);
    }

    #[OA\Post(path: '/api/v1/general-ledger/profit-centers', operationId: 'createGeneralLedgerProfitCenter', description: 'Creates a profit centre; the code is unique in the tenant.', summary: 'Create profit centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreDimensionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Profit centre created', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerDimensionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or code already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ])]
    public function storeProfitCenter(StoreDimensionRequest $request): JsonResponse
    {
        return $this->create(new ProfitCenter, $request);
    }

    #[OA\Put(path: '/api/v1/general-ledger/profit-centers/{id}', operationId: 'updateGeneralLedgerProfitCenter', description: 'Changes code, name or active flag of a profit centre. Postings already made keep it.', summary: 'Update profit centre', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['General Ledger Dimensions'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GeneralLedgerStoreDimensionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Profit centre updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'data', ref: '#/components/schemas/GeneralLedgerDimensionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role or missing ledger write access', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error or code already used', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ])]
    public function updateProfitCenter(StoreDimensionRequest $request, string $id): JsonResponse
    {
        return $this->change(ProfitCenter::findOrFail($id), $request);
    }

    /**
     * @param  Builder<CostCenter>|Builder<ProfitCenter>  $query
     */
    private function list($query, Request $request): JsonResponse
    {
        $rows = $query
            ->when($request->query('active') !== null, static fn ($builder) => $builder->where('is_active', filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('code')
            ->gridPaginate(min(1000, max(1, (int) $request->query('per_page', 1000))));

        return $this->successResponse(
            data: $rows->getCollection()->map(fn (Model $row): array => $this->present($row))->values(),
            meta: ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
        );
    }

    private function create(CostCenter|ProfitCenter $model, StoreDimensionRequest $request): JsonResponse
    {
        $model->fill(['tenant_id' => $this->tenantContext->getTenantId(), 'is_active' => true, ...$request->validated()])->save();

        return $this->successResponse($this->present($model), 'Created', Response::HTTP_CREATED);
    }

    private function change(CostCenter|ProfitCenter $model, StoreDimensionRequest $request): JsonResponse
    {
        $model->update($request->validated());

        return $this->successResponse($this->present($model), 'Updated');
    }

    /**
     * @return array{id: string, code: string, name: string, is_active: bool}
     */
    private function present(Model $row): array
    {
        return ['id' => (string) $row->getKey(), 'code' => (string) $row->getAttribute('code'), 'name' => (string) $row->getAttribute('name'), 'is_active' => (bool) $row->getAttribute('is_active')];
    }
}
