<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StoreReconciliationRuleRequest;
use App\Http\Resources\BankReconciliationRuleResource;
use App\Http\Responses\ApiResponse;
use App\Models\BankReconciliationRule;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configurable reconciliation rules of the current tenant: conditions on amount (with a tolerance or a range),
 * reference, description, counterparty IBAN or name, tried by priority on the lines that match no invoice.
 */
class BankReconciliationRuleController extends Controller
{
    use ApiResponse;

    /**
     * The rules of the tenant, by ascending priority.
     */
    #[OA\Get(
        path: '/api/v1/banking/reconciliation-rules',
        operationId: 'listBankReconciliationRules',
        description: 'Lists the reconciliation rules of the active tenant by ascending priority (the order in which the automatic reconciliation tries them).',
        summary: 'List reconciliation rules',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Banking', 'Reconciliation'],
        responses: [
            new OA\Response(response: 200, description: 'Rules', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/BankingReconciliationRuleResource')),
            ])),
            new OA\Response(ref: '#/components/responses/BankingUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/BankingForbidden', response: 403),
        ]
    )]
    public function index(): JsonResponse
    {
        $rules = BankReconciliationRule::query()->orderBy('priority')->orderBy('name')->get();

        return $this->successResponse(BankReconciliationRuleResource::collection($rules), 'Reconciliation rules');
    }

    /**
     * Creates a rule.
     */
    #[OA\Post(
        path: '/api/v1/banking/reconciliation-rules',
        operationId: 'createBankReconciliationRule',
        description: 'Creates a reconciliation rule. At least one condition is required; an incoming rule can only categorise internal transfers; the ledger account must be in the chart of accounts.',
        summary: 'Create reconciliation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BankingReconciliationRuleRequest')),
        tags: ['Banking', 'Reconciliation'],
        responses: [
            new OA\Response(response: 201, description: 'Rule created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/BankingReconciliationRuleResource'),
            ])),
            new OA\Response(ref: '#/components/responses/BankingUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/BankingForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/BankingValidationError', response: 422),
        ]
    )]
    public function store(StoreReconciliationRuleRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $rule = BankReconciliationRule::query()->create(['tenant_id' => $tenantContext->getTenantId()] + $request->validated());

        return $this->successResponse(new BankReconciliationRuleResource($rule->refresh()), 'Reconciliation rule created', Response::HTTP_CREATED);
    }

    /**
     * Updates a rule.
     */
    #[OA\Put(
        path: '/api/v1/banking/reconciliation-rules/{ruleId}',
        operationId: 'updateBankReconciliationRule',
        description: 'Updates a reconciliation rule of the active tenant; omitted fields keep their value.',
        summary: 'Update reconciliation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BankingReconciliationRuleRequest')),
        tags: ['Banking', 'Reconciliation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/BankingRuleId')],
        responses: [
            new OA\Response(response: 200, description: 'Rule updated', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean'),
                new OA\Property(property: 'message', type: 'string'),
                new OA\Property(property: 'data', ref: '#/components/schemas/BankingReconciliationRuleResource'),
            ])),
            new OA\Response(ref: '#/components/responses/BankingUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/BankingForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/BankingNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/BankingValidationError', response: 422),
        ]
    )]
    public function update(StoreReconciliationRuleRequest $request, string $ruleId): JsonResponse
    {
        $rule = BankReconciliationRule::query()->findOrFail($ruleId);
        $rule->update($request->validated());

        return $this->successResponse(new BankReconciliationRuleResource($rule->refresh()), 'Reconciliation rule updated');
    }

    /**
     * Deletes a rule; lines it already categorised keep their categorisation.
     */
    #[OA\Delete(
        path: '/api/v1/banking/reconciliation-rules/{ruleId}',
        operationId: 'deleteBankReconciliationRule',
        description: 'Deletes a reconciliation rule of the active tenant. Lines it already categorised keep their categorisation and their entry.',
        summary: 'Delete reconciliation rule',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Banking', 'Reconciliation'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/BankingRuleId')],
        responses: [
            new OA\Response(response: 200, description: 'Rule deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/BankingUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/BankingForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/BankingNotFound', response: 404),
        ]
    )]
    public function destroy(string $ruleId): JsonResponse
    {
        BankReconciliationRule::query()->findOrFail($ruleId)->delete();

        return $this->successResponse(null, 'Reconciliation rule deleted');
    }
}
