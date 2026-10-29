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

final class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleServiceInterface $lifecycleService,
        private readonly CreateSubscriptionAction $createAction,
        private readonly ChangePlanAction $changePlanAction,
        private readonly TenantContext $tenantContext
    ) {}

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

    public function activate(Request $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycleService->activateSubscription($id, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription activated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $reason = $request->input('reason', 'Administrative suspension');
        $subscription = $this->lifecycleService->suspendSubscription($id, $reason, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription suspended successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $subscription = $this->lifecycleService->reactivateSubscription($id, $request->user()?->id);

        return response()->json([
            'message' => 'Subscription reactivated successfully',
            'data' => new SubscriptionResource($subscription),
        ]);
    }

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
