<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Contracts\RecurringBillingServiceInterface;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Http\Requests\ExecuteBillingRunRequest;
use Modules\SubscriptionBilling\Http\Requests\RecordUsageRequest;
use Modules\SubscriptionBilling\Http\Resources\BillingRunResource;
use Modules\SubscriptionBilling\Http\Resources\UsageEventResource;
use Modules\SubscriptionBilling\Models\BillingRun;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageEvent;
use OpenApi\Attributes as OA;

/**
 * Metered usage records and the billing runs of the active company.
 */
final class UsageBillingController extends Controller
{
    public function __construct(
        private readonly UsageBillingServiceInterface $usage,
        private readonly RecurringBillingServiceInterface $billing,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Records a usage event (idempotent on its key).
     */
    #[OA\Post(path: '/api/v1/subscriptions/usage', operationId: 'recordUsageSubscriptionsUsage', summary: 'Record usage', description: 'Records a usage event on a metered item of a live subscription; the same idempotency key returns the event already recorded. Usage is billed in arrears with the next invoice.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Usage'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingRecordUsageRequest')), responses: [
        new OA\Response(response: 201, description: 'Usage recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingUsageEventResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function recordUsage(RecordUsageRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();
        $item = SubscriptionItem::query()->findOrFail($data['subscription_item_id']);

        $event = $this->usage->recordUsage(new RecordUsageDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            subscriptionId: (string) $item->subscription_id,
            subscriptionItemId: (string) $item->id,
            metricCode: $data['metric_code'],
            quantity: (string) $data['quantity'],
            eventTimestamp: $data['event_timestamp'] ?? null,
            idempotencyKey: $data['idempotency_key'] ?? null,
            metadata: $data['metadata'] ?? [],
        ));

        return response()->json(['message' => 'Usage recorded successfully', 'data' => (new UsageEventResource($event))->resolve()], 201);
    }

    /**
     * Paginated usage events, latest first.
     */
    #[OA\Get(path: '/api/v1/subscriptions/usage', operationId: 'listUsageEventsSubscriptionsUsage', summary: 'List usage events', description: 'Usage events of the active company, latest first, by subscription and metric.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Usage'], parameters: [
        new OA\Parameter(name: 'subscription_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'metric_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Usage events', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingUsageEventResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/SubscriptionBillingPaginationMeta')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
    ])]
    public function listUsageEvents(Request $request): JsonResponse
    {
        $events = UsageEvent::query()
            ->when($request->filled('subscription_id'), fn ($query) => $query->where('subscription_id', (string) $request->query('subscription_id')))
            ->when($request->filled('metric_code'), fn ($query) => $query->where('metric_code', (string) $request->query('metric_code')))
            ->orderByDesc('event_timestamp')
            ->paginate(RequestedPerPage::resolve(50));

        return response()->json([
            'data' => UsageEventResource::collection($events->getCollection())->resolve(),
            'meta' => ['current_page' => $events->currentPage(), 'last_page' => $events->lastPage(), 'total' => $events->total()],
        ]);
    }

    /**
     * Bills the due periods of the company (or of one subscription).
     */
    #[OA\Post(path: '/api/v1/subscriptions/billing-runs', operationId: 'executeBillingRunSubscriptionsBillingRuns', summary: 'Execute billing run', description: 'Bills every period due up to run date + lookahead through the invoicing services: one invoice per subscription and period, however many times it runs.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Billing Runs'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingExecuteBillingRunRequest')), responses: [
        new OA\Response(response: 200, description: 'Billing run', content: new OA\JsonContent(properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingBillingRunResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function executeBillingRun(ExecuteBillingRunRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();
        $userId = $request->user()?->getAuthIdentifier();

        $run = $this->billing->executeBillingRun(new ExecuteBillingRunDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            runDate: $data['run_date'] ?? null,
            lookaheadDays: (int) ($data['lookahead_days'] ?? 0),
            executedBy: $userId !== null ? (string) $userId : null,
            subscriptionId: $data['subscription_id'] ?? null,
        ));

        return response()->json(['message' => 'Billing run executed', 'data' => (new BillingRunResource($run))->resolve()]);
    }

    /**
     * Paginated billing runs, latest first.
     */
    #[OA\Get(path: '/api/v1/subscriptions/billing-runs', operationId: 'listSubscriptionsBillingRuns', summary: 'List billing runs', description: 'Billing runs of the active company, latest first.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Billing Runs'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPage')], responses: [
        new OA\Response(response: 200, description: 'Billing runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingBillingRunResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/SubscriptionBillingPaginationMeta')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
    ])]
    public function listBillingRuns(): JsonResponse
    {
        $runs = BillingRun::query()->orderByDesc('created_at')->paginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => BillingRunResource::collection($runs->getCollection())->resolve(),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    /**
     * A billing run with its errors.
     */
    #[OA\Get(path: '/api/v1/subscriptions/billing-runs/{id}', operationId: 'getSubscriptionsBillingRun', summary: 'Get billing run', description: 'A billing run of the active company with its outcome and errors.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Billing Runs'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingRunId')], responses: [
        new OA\Response(response: 200, description: 'Billing run', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingBillingRunResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function showBillingRun(string $id): JsonResponse
    {
        return response()->json(['data' => (new BillingRunResource(BillingRun::query()->findOrFail($id)))->resolve()]);
    }
}
