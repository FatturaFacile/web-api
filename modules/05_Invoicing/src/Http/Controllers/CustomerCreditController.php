<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\EvaluateCustomerCreditAction;
use Modules\Invoicing\Actions\ReleaseSalesOrderHoldAction;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Http\Requests\UpdateCreditLimitRequest;
use Modules\Invoicing\Http\Resources\CustomerCreditProfileResource;
use Modules\Invoicing\Models\SalesOrderHold;
use OpenApi\Attributes as OA;

final class CustomerCreditController extends Controller
{
    /**
     * Returns the credit profile of a customer.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/customer-credit/{customerId}',
        operationId: 'getInvoicingCustomerCredit',
        description: 'Returns the credit profile of a customer.',
        summary: 'Get customer credit profile',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Customer Credit'],
        parameters: [
            new OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'Identifier of the customer', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCustomerCreditProfileResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $customerId, EvaluateCustomerCreditAction $action, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $profile = $action->execute($customerId, $tenantId);

        return response()->json(new CustomerCreditProfileResource($profile));
    }

    /**
     * Updates the credit limit of a customer.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/customer-credit/limit',
        operationId: 'updateLimitInvoicingCustomerCreditLimit',
        description: 'Updates the credit limit of a customer.',
        summary: 'Update customer credit limit',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Customer Credit'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InvoicingUpdateCreditLimitRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/InvoicingCustomerCreditProfileResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateLimit(
        UpdateCreditLimitRequest $request,
        CustomerCreditServiceInterface $service,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $tenantId = $tenantContext->getTenantId();
        $userId = (string) ($request->user()?->id ?? 'system');

        $profile = $service->updateCreditLimit(
            customerId: (string) $validated['customer_id'],
            newLimit: (float) $validated['credit_limit'],
            userId: $userId,
            tenantId: $tenantId
        );

        return response()->json(new CustomerCreditProfileResource($profile));
    }

    /**
     * Releases a credit hold on a sales order, recording the optional notes.
     */
    #[OA\Post(
        path: '/api/v1/invoicing/customer-credit/holds/{holdId}/release',
        operationId: 'releaseHoldInvoicingCustomerCreditHold',
        description: 'Releases a credit hold on a sales order, recording the optional notes.',
        summary: 'Release credit hold',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Customer Credit'],
        parameters: [
            new OA\Parameter(name: 'holdId', in: 'path', required: true, description: 'Identifier of the hold', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'notes')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'hold_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function releaseHold(string $holdId, ReleaseSalesOrderHoldAction $action, Request $request): JsonResponse
    {
        $hold = SalesOrderHold::findOrFail($holdId);
        $userId = (string) ($request->user()?->id ?? 'system');
        $notes = $request->input('notes');

        $released = $action->execute($hold, $userId, $notes);

        return response()->json([
            'message' => 'Hold released successfully',
            'hold_id' => $released->id,
            'status' => $released->status,
        ]);
    }
}
