<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Http\Requests\CreatePlanRequest;
use Modules\SubscriptionBilling\Http\Resources\PlanResource;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;

final class PlanController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = SubscriptionPlan::where('tenant_id', $tenantId);

        if ($request->has('interval')) {
            $query->where('billing_interval', $request->query('interval'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $plans = $query->orderBy('name')->paginate(25);

        return response()->json([
            'data' => PlanResource::collection($plans),
            'meta' => [
                'current_page' => $plans->currentPage(),
                'total' => $plans->total(),
            ],
        ]);
    }

    public function store(CreatePlanRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $plan = SubscriptionPlan::create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'product_id' => $validated['product_id'] ?? null,
            'plan_code' => $validated['plan_code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'billing_interval' => $validated['billing_interval'],
            'interval_count' => (int) ($validated['interval_count'] ?? 1),
            'pricing_model' => $validated['pricing_model'] ?? 'flat_fee',
            'currency' => $validated['currency'] ?? 'EUR',
            'setup_fee' => (float) ($validated['setup_fee'] ?? 0.00),
            'base_price' => (float) $validated['base_price'],
            'trial_period_days' => (int) ($validated['trial_period_days'] ?? 0),
            'min_commitment_cycles' => (int) ($validated['min_commitment_cycles'] ?? 1),
            'is_active' => true,
            'translations' => $validated['translations'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        if (! empty($validated['tiers'])) {
            foreach ($validated['tiers'] as $tier) {
                PricingTier::create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'tier_number' => (int) ($tier['tier_number'] ?? 1),
                    'from_quantity' => (float) ($tier['from_quantity'] ?? 0),
                    'to_quantity' => isset($tier['to_quantity']) ? (float) $tier['to_quantity'] : null,
                    'unit_price' => (float) ($tier['unit_price'] ?? 0),
                    'flat_fee' => (float) ($tier['flat_fee'] ?? 0),
                ]);
            }
        }

        if (! empty($validated['features'])) {
            foreach ($validated['features'] as $feature) {
                PlanFeature::create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'feature_code' => $feature['feature_code'],
                    'name' => $feature['name'],
                    'metric_type' => $feature['metric_type'] ?? 'metered_quota',
                    'included_quantity' => (float) ($feature['included_quantity'] ?? 0),
                    'overage_unit_price' => (float) ($feature['overage_unit_price'] ?? 0),
                    'is_active' => true,
                ]);
            }
        }

        return response()->json([
            'message' => 'Subscription plan created successfully',
            'data' => new PlanResource($plan),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $plan = SubscriptionPlan::with(['pricingTiers', 'features'])
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => new PlanResource($plan),
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
