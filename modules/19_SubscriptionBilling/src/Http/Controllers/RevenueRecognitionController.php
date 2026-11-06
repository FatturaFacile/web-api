<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Actions\RecognizeRevenueAction;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Http\Requests\RecognizeRevenueRequest;
use Modules\SubscriptionBilling\Http\Resources\RevenueContractResource;
use Modules\SubscriptionBilling\Models\RevenueContract;
use OpenApi\Attributes as OA;

final class RevenueRecognitionController extends Controller
{
    public function __construct(
        private readonly RecognizeRevenueAction $recognizeAction,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated revenue contracts of the active tenant, optionally filtered by status.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/revenue-contracts',
        operationId: 'listContractsSubscriptionsRevenueContracts',
        description: 'Returns the paginated revenue contracts of the active tenant, optionally filtered by status.',
        summary: 'List revenue contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Revenue Contracts'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingRevenueContractResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listContracts(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = RevenueContract::where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $contracts = $query->orderByDesc('inception_date')->paginate(25);

        return response()->json([
            'data' => RevenueContractResource::collection($contracts),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
            ],
        ]);
    }

    /**
     * Returns a revenue contract with its performance obligations and revenue schedules.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/revenue-contracts/{id}',
        operationId: 'getShowContractSubscriptionsRevenueContracts',
        description: 'Returns a revenue contract with its performance obligations and revenue schedules.',
        summary: 'Get revenue contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Revenue Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the revenue contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingRevenueContractResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showContract(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $contract = RevenueContract::with(['performanceObligations.revenueSchedules'])
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => new RevenueContractResource($contract),
        ]);
    }

    /**
     * Recognizes the revenue due at the given period date.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/revenue-recognition/recognize',
        operationId: 'recognizeBatchSubscriptionsRevenueRecognitionRecognize',
        description: 'Recognizes the revenue due at the given period date.',
        summary: 'Recognize revenue batch',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Revenue Recognition'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingRecognizeRevenueRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recognizeBatch(RecognizeRevenueRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecognizeRevenueDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            periodDate: $validated['period_date'] ?? null,
            executedBy: $request->user()?->id
        );

        $result = $this->recognizeAction->execute($dto);

        return response()->json([
            'message' => 'Revenue recognized successfully',
            'data' => $result,
        ]);
    }

    private function resolveTenantId(Request $request): string
    {
        $tenant = $this->tenantContext->getTenant();
        if ($tenant) {
            return (string) $tenant->id;
        }

        $user = $request->user();

        return (string) ($user?->current_tenant_id ?? $user?->tenant_id ?? '019318b7-0000-7000-8000-000000000001');
    }
}
