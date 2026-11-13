<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Http\Resources\CustomerLedgerEntryResource;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use OpenApi\Attributes as OA;

final class CustomerLedgerController extends Controller
{
    /**
     * Returns the paginated ledger entries of a customer in chronological order.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/customer-ledger/{customerId}/statement',
        operationId: 'getStatementInvoicingCustomerLedger',
        description: 'Returns the paginated ledger entries of a customer in chronological order.',
        summary: 'Get customer statement',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Customer Ledger'],
        parameters: [
            new OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'Identifier of the customer', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 50)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InvoicingCustomerLedgerEntryResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function statement(string $customerId, Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();

        $entries = CustomerLedgerEntry::where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->orderBy('entry_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->gridPaginate((int) $request->query('per_page', 50));

        return response()->json(CustomerLedgerEntryResource::collection($entries)->response()->getData(true));
    }

    /**
     * Returns the aging buckets of a customer.
     */
    #[OA\Get(
        path: '/api/v1/invoicing/customer-ledger/{customerId}/aging',
        operationId: 'getAgingInvoicingCustomerLedger',
        description: 'Returns the aging buckets of a customer.',
        summary: 'Get customer aging',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Invoicing Customer Ledger'],
        parameters: [
            new OA\Parameter(name: 'customerId', in: 'path', required: true, description: 'Identifier of the customer', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'customer_id'), new OA\Property(property: 'tenant_id', type: 'string'), new OA\Property(property: 'aging_buckets', type: 'object', properties: [new OA\Property(property: 'current', type: 'number'), new OA\Property(property: 'days_1_30', type: 'number'), new OA\Property(property: 'days_31_60', type: 'number'), new OA\Property(property: 'days_61_90', type: 'number'), new OA\Property(property: 'days_90_plus', type: 'number'), new OA\Property(property: 'total_overdue', type: 'number'), new OA\Property(property: 'total_outstanding', type: 'number')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function aging(string $customerId, CustomerLedgerServiceInterface $service, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $aging = $service->calculateAging($customerId, $tenantId);

        return response()->json([
            'customer_id' => $customerId,
            'tenant_id' => $tenantId,
            'aging_buckets' => $aging,
        ]);
    }
}
