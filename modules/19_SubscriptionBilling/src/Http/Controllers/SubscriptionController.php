<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Actions\ChangePlanAction;
use Modules\SubscriptionBilling\Actions\CreateSubscriptionAction;
use Modules\SubscriptionBilling\Contracts\SubscriptionLifecycleServiceInterface;
use Modules\SubscriptionBilling\DTOs\CancelSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\CreateSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\DowngradeSubscriptionDTO;
use Modules\SubscriptionBilling\DTOs\UpgradeSubscriptionDTO;
use Modules\SubscriptionBilling\Http\Requests\CancelSubscriptionRequest;
use Modules\SubscriptionBilling\Http\Requests\CreateSubscriptionRequest;
use Modules\SubscriptionBilling\Http\Requests\DowngradePlanRequest;
use Modules\SubscriptionBilling\Http\Requests\UpgradePlanRequest;
use Modules\SubscriptionBilling\Http\Resources\SubscriptionResource;
use Modules\SubscriptionBilling\Models\Subscription;
use OpenApi\Attributes as OA;

final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleServiceInterface $lifecycleService,
        private readonly CreateSubscriptionAction $createAction,
        private readonly ChangePlanAction $changePlanAction,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of contracts of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/sub/contracts',
        operationId: 'listSubContracts',
        description: 'Returns the paginated list of contracts of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List contracts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'customer_id', in: 'query', required: false, description: 'Filter by customer', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of contracts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = Subscription::with(['plan', 'items'])
            ->where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        $subscriptions = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => SubscriptionResource::collection($subscriptions),
            'meta' => [
                'current_page' => $subscriptions->currentPage(),
                'total' => $subscriptions->total(),
            ],
        ]);
    }

    /**
     * Creates a new contract in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts',
        operationId: 'createSubContract',
        description: 'Creates a new contract in the active tenant.',
        summary: 'Create contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCreateSubscriptionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Contract created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new CreateSubscriptionDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            customerId: $validated['customer_id'],
            planId: $validated['plan_id'],
            startDate: $validated['start_date'],
            contractTermMonths: (int) ($validated['contract_term_months'] ?? 12),
            autoRenew: (bool) ($validated['auto_renew'] ?? true),
            billingDayOfMonth: (int) ($validated['billing_day_of_month'] ?? 1),
            paymentTermsDays: (int) ($validated['payment_terms_days'] ?? 30),
            paymentMethod: $validated['payment_method'] ?? 'sepa_direct_debit',
            bankAccountId: $validated['bank_account_id'] ?? null,
            items: $validated['items'] ?? [],
            metadata: $validated['metadata'] ?? []
        );

        $subscription = $this->createAction->execute($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription created successfully',
            'data' => new SubscriptionResource($subscription),
        ], 201);
    }

    /**
     * Returns a single contract of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/sub/contracts/{id}',
        operationId: 'getSubContract',
        description: 'Returns a single contract of the active tenant.',
        summary: 'Get contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $subscription = Subscription::with(['plan.features', 'items', 'versions'])
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Activates a subscription.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/activate',
        operationId: 'activateSubContract',
        description: 'Activates a subscription contract.',
        summary: 'Activate subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function activate(Request $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycleService->activateSubscription($id, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription activated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Suspends a subscription, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/suspend',
        operationId: 'suspendSubContract',
        description: 'Suspends a subscription contract, recording the reason.',
        summary: 'Suspend subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'reason', type: 'string', default: 'Administrative suspension')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function suspend(Request $request, string $id): JsonResponse
    {
        $reason = $request->input('reason', 'Administrative suspension');
        $subscription = $this->lifecycleService->suspendSubscription($id, $reason, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription suspended successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Reactivates a suspended subscription.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/reactivate',
        operationId: 'reactivateSubContract',
        description: 'Reactivates a suspended subscription contract.',
        summary: 'Reactivate subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function reactivate(Request $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycleService->reactivateSubscription($id, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription reactivated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Moves a subscription to a higher plan.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/upgrade',
        operationId: 'upgradeSubContract',
        description: 'Moves a subscription contract to a higher plan.',
        summary: 'Upgrade subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingUpgradePlanRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function upgrade(UpgradePlanRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $dto = new UpgradeSubscriptionDTO(
            subscriptionId: $id,
            newPlanId: $validated['new_plan_id'],
            effectiveDate: $validated['effective_date'] ?? null,
            changeReason: $validated['change_reason'] ?? null,
            actorUserId: $request->user()?->id
        );

        $subscription = $this->changePlanAction->upgrade($dto);

        return response()->json([
            'message' => 'Subscription upgraded successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Moves a subscription to a lower plan.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/downgrade',
        operationId: 'downgradeSubContract',
        description: 'Moves a subscription contract to a lower plan.',
        summary: 'Downgrade subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingDowngradePlanRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function downgrade(DowngradePlanRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $dto = new DowngradeSubscriptionDTO(
            subscriptionId: $id,
            newPlanId: $validated['new_plan_id'],
            effectiveDate: $validated['effective_date'] ?? null,
            changeReason: $validated['change_reason'] ?? null,
            actorUserId: $request->user()?->id
        );

        $subscription = $this->changePlanAction->downgrade($dto);

        return response()->json([
            'message' => 'Subscription downgraded successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    /**
     * Cancels a subscription contract at the given date, recording the reason.
     */
    #[OA\Post(
        path: '/api/v1/sub/contracts/{id}/cancel',
        operationId: 'cancelSubContract',
        description: 'Cancels a subscription contract at the given date, recording the reason.',
        summary: 'Cancel subscription contract',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the contract', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCancelSubscriptionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingSubscriptionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function cancel(CancelSubscriptionRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $dto = new CancelSubscriptionDTO(
            subscriptionId: $id,
            reason: $validated['reason'],
            cancellationDate: $validated['cancellation_date'] ?? null,
            actorUserId: $request->user()?->id
        );

        $subscription = $this->lifecycleService->cancelSubscription($dto);

        return response()->json([
            'message' => 'Subscription cancelled successfully',
            'data' => new SubscriptionResource($subscription),
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
