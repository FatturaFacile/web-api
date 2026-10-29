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

final class UsageBillingController extends Controller
{
    public function __construct(
        private readonly UsageBillingServiceInterface $usageService,
        private readonly ExecuteBillingRunAction $billingRunAction,
        private readonly TenantContext $tenantContext
    ) {}

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
