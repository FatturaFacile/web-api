<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Actions\ExecuteBillingRunAction;
use Modules\SubscriptionBilling\Contracts\UsageBillingServiceInterface;
use Modules\SubscriptionBilling\DTOs\ExecuteBillingRunDTO;
use Modules\SubscriptionBilling\DTOs\RecordUsageDTO;
use Modules\SubscriptionBilling\Http\Requests\ExecuteBillingRunRequest;
use Modules\SubscriptionBilling\Http\Requests\RecordUsageRequest;
use Modules\SubscriptionBilling\Http\Resources\BillingRunResource;
use Modules\SubscriptionBilling\Http\Resources\UsageEventResource;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\UsageEvent;
use OpenApi\Attributes as OA;

final class UsageBillingController extends Controller
{
    public function __construct(
        private readonly UsageBillingServiceInterface $usageService,
        private readonly ExecuteBillingRunAction $billingRunAction,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Records a metered usage event on a subscription item.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/usage',
        operationId: 'recordUsageSubscriptionsUsage',
        description: 'Records a metered usage event on a subscription item.',
        summary: 'Record usage event',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Usage'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingRecordUsageRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Usage created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingUsageEventResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recordUsage(RecordUsageRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $item = SubscriptionItem::where('id', $validated['subscription_item_id'])->firstOrFail();

        $dto = new RecordUsageDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            subscriptionId: $item->subscription_id,
            subscriptionItemId: $item->id,
            metricCode: $validated['metric_code'],
            quantity: (float) $validated['quantity'],
            eventTimestamp: $validated['event_timestamp'] ?? null,
            idempotencyKey: $validated['idempotency_key'] ?? null,
            metadata: $validated['metadata'] ?? []
        );

        $event = $this->usageService->recordUsage($dto);

        return response()->json([
            'message' => 'Usage recorded successfully',
            'data' => new UsageEventResource($event),
        ], 201);
    }

    /**
     * Returns the paginated usage events of the active tenant, latest first, optionally filtered by subscription and metric.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/usage',
        operationId: 'listUsageEventsSubscriptionsUsage',
        description: 'Returns the paginated usage events of the active tenant, latest first, optionally filtered by subscription and metric.',
        summary: 'List usage events',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Usage'],
        parameters: [
            new OA\Parameter(name: 'subscription_id', in: 'query', required: false, description: 'Filter by subscription', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'metric_code', in: 'query', required: false, description: 'Filter by metric code', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingUsageEventResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function listUsageEvents(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = UsageEvent::where('tenant_id', $tenantId);

        if ($request->has('subscription_id')) {
            $query->where('subscription_id', $request->query('subscription_id'));
        }

        if ($request->has('metric_code')) {
            $query->where('metric_code', $request->query('metric_code'));
        }

        $events = $query->orderByDesc('event_timestamp')->paginate(50);

        return response()->json([
            'data' => UsageEventResource::collection($events),
            'meta' => [
                'current_page' => $events->currentPage(),
                'total' => $events->total(),
            ],
        ]);
    }

    /**
     * Executes a billing run at the given date with the given lookahead.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/billing-runs',
        operationId: 'executeBillingRunSubscriptionsBillingRuns',
        description: 'Executes a billing run at the given date with the given lookahead.',
        summary: 'Execute billing run',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Billing Runs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingExecuteBillingRunRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingBillingRunResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function executeBillingRun(ExecuteBillingRunRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new ExecuteBillingRunDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            runDate: $validated['run_date'] ?? null,
            lookaheadDays: (int) ($validated['lookahead_days'] ?? 0),
            executedBy: $request->user()?->id
        );

        $billingRun = $this->billingRunAction->execute($dto);

        return response()->json([
            'message' => 'Billing run executed successfully',
            'data' => new BillingRunResource($billingRun),
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
