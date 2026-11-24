<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Http\Requests\RecognizeRevenueRequest;
use Modules\SubscriptionBilling\Http\Resources\RevenueContractResource;
use Modules\SubscriptionBilling\Models\RevenueContract;
use OpenApi\Attributes as OA;

/**
 * IFRS 15 revenue contracts of the subscriptions and the recognition posted to the General Ledger.
 */
final class RevenueRecognitionController extends Controller
{
    public function __construct(
        private readonly RevenueRecognitionServiceInterface $revenue,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Paginated revenue contracts.
     */
    #[OA\Get(path: '/api/v1/subscriptions/revenue-contracts', operationId: 'listContractsSubscriptionsRevenueContracts', summary: 'List revenue contracts', description: 'Revenue contracts of the active company, by status.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Revenue Recognition'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Revenue contracts', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingRevenueContractResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/SubscriptionBillingPaginationMeta')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
    ])]
    public function listContracts(Request $request): JsonResponse
    {
        $contracts = RevenueContract::query()
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->orderByDesc('inception_date')
            ->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => RevenueContractResource::collection($contracts->getCollection())->resolve(),
            'meta' => ['current_page' => $contracts->currentPage(), 'last_page' => $contracts->lastPage(), 'total' => $contracts->total()],
        ]);
    }

    /**
     * A revenue contract with its obligations and slices.
     */
    #[OA\Get(path: '/api/v1/subscriptions/revenue-contracts/{id}', operationId: 'getShowContractSubscriptionsRevenueContracts', summary: 'Get revenue contract', description: 'A revenue contract with its performance obligations and monthly slices.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Revenue Recognition'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingRevenueContractId')], responses: [
        new OA\Response(response: 200, description: 'Revenue contract', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingRevenueContractResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function showContract(string $id): JsonResponse
    {
        $contract = RevenueContract::query()->with(['performanceObligations.revenueSchedules'])->findOrFail($id);

        return response()->json(['data' => (new RevenueContractResource($contract))->resolve()]);
    }

    /**
     * Posts the deferred revenue due up to the period date.
     */
    #[OA\Post(path: '/api/v1/subscriptions/revenue-recognition/recognize', operationId: 'recognizeBatchSubscriptionsRevenueRecognitionRecognize', summary: 'Recognize revenue', description: 'Posts one journal entry per month (Dr deferred revenue, Cr revenue) through the General Ledger posting service for the slices of issued documents due up to period_date. Running it again posts nothing twice; a closed period is reported in errors.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Revenue Recognition'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingRecognizeRevenueRequest')), responses: [
        new OA\Response(response: 200, description: 'Recognition result', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingRecognitionResult')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function recognizeBatch(RecognizeRevenueRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $userId = $request->user()?->getAuthIdentifier();

        $result = $this->revenue->recognizeRevenue(new RecognizeRevenueDTO($tenantId, $tenantId, $request->validated('period_date'), $userId !== null ? (string) $userId : null));

        return response()->json(['message' => 'Revenue recognized', 'data' => $result]);
    }
}
