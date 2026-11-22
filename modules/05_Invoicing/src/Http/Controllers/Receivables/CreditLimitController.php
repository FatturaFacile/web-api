<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers\Receivables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Http\Requests\Receivables\GrantCreditOverrideRequest;
use Modules\Invoicing\Http\Requests\Receivables\ReceivablesQueryRequest;
use Modules\Invoicing\Http\Requests\Receivables\RevokeCreditOverrideRequest;
use Modules\Invoicing\Http\Requests\Receivables\StoreCustomerCreditLimitRequest;
use Modules\Invoicing\Http\Requests\Receivables\UpdateCustomerCreditLimitRequest;
use Modules\Invoicing\Http\Resources\CustomerCreditLimitResource;
use Modules\Invoicing\Http\Resources\CustomerCreditOverrideResource;
use Modules\Invoicing\Models\CustomerCreditLimit;
use Modules\Invoicing\Models\CustomerCreditOverride;
use Modules\Invoicing\Services\Receivables\CreditEnforcementService;
use Modules\Invoicing\Services\Receivables\CreditLimitService;
use Modules\Invoicing\Services\Receivables\CreditRiskService;
use OpenApi\Attributes as OA;

final class CreditLimitController extends ReceivablesController
{
    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/credit', operationId: 'getInvoicingReceivablesCustomerCredit', summary: 'Credit position of a customer: limit, exposure components, utilisation, block state, overrides', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Credit position'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function status(string $customerId, CreditLimitService $limits): JsonResponse
    {
        $this->ensureCustomer($customerId);
        $status = $limits->status($this->tenantId(), $customerId);

        return response()->json(['data' => array_merge($status, [
            'limit' => $status['limit'] !== null ? new CustomerCreditLimitResource($status['limit']) : null,
            'overrides' => CustomerCreditOverrideResource::collection($status['overrides']),
        ])]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/risk', operationId: 'getInvoicingReceivablesCustomerRisk', summary: 'Composite credit risk assessment of a customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Risk score, rating and factors'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function risk(string $customerId, Request $request, CreditRiskService $risk): JsonResponse
    {
        $this->ensureCustomer($customerId);

        return response()->json(['data' => $risk->assess($this->tenantId(), $customerId, $this->today($request))]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/credit-check', operationId: 'checkInvoicingReceivablesCredit', summary: 'Credit check of an operation (order confirmation, delivery note issue)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], responses: [new OA\Response(response: 200, description: 'Decision: ok, warning or block'), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function check(ReceivablesQueryRequest $request, CreditEnforcementService $enforcement): JsonResponse
    {
        $validated = $request->validated();
        abort_unless(isset($validated['customer_id']), 422, 'customer_id is required');

        $decision = $enforcement->evaluate($this->tenantId(), (string) $validated['customer_id'], (string) ($validated['scope'] ?? 'order'), (string) ($validated['amount'] ?? '0'));

        return response()->json(['data' => $decision->toArray()]);
    }

    #[OA\Get(path: '/api/v1/invoicing/receivables/customers/{customerId}/credit-limits', operationId: 'listInvoicingReceivablesCreditLimits', summary: 'Credit limits of a customer over time', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Limit history'), new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function index(string $customerId, CreditLimitService $limits): JsonResponse
    {
        $this->ensureCustomer($customerId);

        return response()->json(['data' => CustomerCreditLimitResource::collection($limits->history($this->tenantId(), $customerId))]);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/credit-limits', operationId: 'storeInvoicingReceivablesCreditLimit', summary: 'Create a credit limit with validity, insurance and reaction policy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'Limit created'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')), new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse'))])]
    public function store(StoreCustomerCreditLimitRequest $request, CreditLimitService $limits): JsonResponse
    {
        $this->authorizeManage();
        $limit = $limits->create($this->tenantId(), $request->validated() + ['insured_limit' => 0, 'coverage_percentage' => 0, 'overdue_tolerance_amount' => 0], $this->userId($request));

        return response()->json(['data' => new CustomerCreditLimitResource($limit)], 201);
    }

    #[OA\Put(path: '/api/v1/invoicing/receivables/credit-limits/{id}', operationId: 'updateInvoicingReceivablesCreditLimit', summary: 'Update a credit limit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'Limit updated'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function update(string $id, UpdateCustomerCreditLimitRequest $request, CreditLimitService $limits): JsonResponse
    {
        $this->authorizeManage();
        $limit = CustomerCreditLimit::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return response()->json(['data' => new CustomerCreditLimitResource($limits->update($limit, $request->validated(), $this->userId($request)))]);
    }

    #[OA\Delete(path: '/api/v1/invoicing/receivables/credit-limits/{id}', operationId: 'deleteInvoicingReceivablesCreditLimit', summary: 'Delete a credit limit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Limit deleted'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function destroy(string $id, Request $request, CreditLimitService $limits): JsonResponse
    {
        $this->authorizeManage();
        $limits->delete(CustomerCreditLimit::query()->where('tenant_id', $this->tenantId())->findOrFail($id), $this->userId($request));

        return response()->json(null, 204);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/customers/{customerId}/overrides', operationId: 'grantInvoicingReceivablesCreditOverride', summary: 'Override the credit block of a customer (audited)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'customerId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 201, description: 'Override granted'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function grantOverride(string $customerId, GrantCreditOverrideRequest $request, CreditLimitService $limits): JsonResponse
    {
        $this->authorizeManage();
        $this->ensureCustomer($customerId);
        $validated = $request->validated();
        $override = $limits->grantOverride($this->tenantId(), $customerId, (string) $validated['scope'], (string) $validated['reason'], $validated['expires_at'] ?? null, (string) $this->userId($request));

        return response()->json(['data' => new CustomerCreditOverrideResource($override)], 201);
    }

    #[OA\Post(path: '/api/v1/invoicing/receivables/overrides/{id}/revoke', operationId: 'revokeInvoicingReceivablesCreditOverride', summary: 'Revoke a credit override', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Invoicing Receivables'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(type: 'object')), responses: [new OA\Response(response: 200, description: 'Override revoked'), new OA\Response(response: 403, description: 'Not a credit manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function revokeOverride(string $id, RevokeCreditOverrideRequest $request, CreditLimitService $limits): JsonResponse
    {
        $this->authorizeManage();
        $override = CustomerCreditOverride::query()->where('tenant_id', $this->tenantId())->findOrFail($id);

        return response()->json(['data' => new CustomerCreditOverrideResource($limits->revokeOverride($override, (string) $this->userId($request), $request->validated('reason')))]);
    }
}
