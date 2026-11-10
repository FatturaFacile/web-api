<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Contracts\ReceivableCollectionServiceInterface;
use Modules\Treasury\Http\Resources\ReceivableCollectionResource;
use Modules\Treasury\Models\ReceivableCollection;
use OpenApi\Attributes as OA;

final class ReceivableCollectionController extends Controller
{
    public function __construct(
        private readonly ReceivableCollectionServiceInterface $collectionService
    ) {}

    /**
     * Returns the paginated list of collections of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/treasury/collections',
        operationId: 'listTreasuryCollections',
        description: 'Returns the paginated list of collections of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List collections',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Collections'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Filter by customer', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of collections', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryReceivableCollectionResource')), new OA\Property(property: 'links', ref: '#/components/schemas/TreasuryPaginatorLinks'), new OA\Property(property: 'meta', ref: '#/components/schemas/TreasuryPaginatorMeta')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $query = ReceivableCollection::where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $collections = $query->latest('due_date')->paginate((int) $request->query('per_page', 25));

        return response()->json(ReceivableCollectionResource::collection($collections)->response()->getData(true));
    }

    /**
     * Returns the customer aging of the receivables, optionally for a single company.
     */
    #[OA\Get(
        path: '/api/v1/treasury/collections/aging',
        operationId: 'getAgingTreasuryCollection',
        description: 'Returns the customer aging of the receivables, optionally for a single company.',
        summary: 'Get receivables aging',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Collections'],
        parameters: [
            new OA\Parameter(name: 'company_id', in: 'query', required: false, description: 'Filter by company', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Aging buckets (bare object, no envelope)', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryCollectionsAging')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function aging(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $companyId = $request->query('company_id');

        $aging = $this->collectionService->getCustomerAging($tenantId, $companyId);

        return response()->json($aging);
    }

    /**
     * Records an amount received against a receivable collection.
     */
    #[OA\Post(
        path: '/api/v1/treasury/collections/{id}/record',
        operationId: 'recordCollectionTreasuryCollection',
        description: 'Records an amount received against a receivable collection.',
        summary: 'Record received collection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Collections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the collection', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['amount'], properties: [new OA\Property(property: 'amount', type: 'number'), new OA\Property(property: 'collection_date', type: 'string', format: 'date', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/TreasuryReceivableCollectionResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recordCollection(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'collection_date' => ['nullable', 'date'],
        ]);

        $date = (string) $request->input('collection_date', now()->toDateString());
        $collection = $this->collectionService->recordCollectionReceived($id, (float) $request->input('amount'), $date);

        return response()->json(new ReceivableCollectionResource($collection));
    }

    /**
     * Returns the overdue receivables that are ready for dunning, with their count.
     */
    #[OA\Get(
        path: '/api/v1/treasury/collections/dunning-readiness',
        operationId: 'getEvaluateDunningTreasuryCollectionsDunningReadiness',
        description: 'Returns the overdue receivables that are ready for dunning, with their count.',
        summary: 'Evaluate dunning readiness',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Treasury Collections'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'evaluated_count', type: 'integer'), new OA\Property(property: 'overdue_items', type: 'array', items: new OA\Items(ref: '#/components/schemas/TreasuryReceivableCollectionResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function evaluateDunning(TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $dunningCandidates = $this->collectionService->evaluateDunningReadiness($tenantId);

        return response()->json([
            'evaluated_count' => count($dunningCandidates),
            'overdue_items' => ReceivableCollectionResource::collection($dunningCandidates),
        ]);
    }
}
