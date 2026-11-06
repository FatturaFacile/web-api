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
use OpenApi\Attributes as OA;

final class PlanController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of plans of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/plans',
        operationId: 'listSubscriptionsPlans',
        description: 'Returns the paginated list of plans of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Plans'],
        parameters: [
            new OA\Parameter(name: 'interval', in: 'query', required: false, description: 'Filter by interval', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, description: 'Filter by is active', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of plans', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingPlanResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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

    /**
     * Creates a new plan in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/plans',
        operationId: 'createSubscriptionsPlan',
        description: 'Creates a new plan in the active tenant.',
        summary: 'Create plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Plans'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCreatePlanRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingPlanResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

    /**
     * Returns a single plan of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/plans/{id}',
        operationId: 'getSubscriptionsPlan',
        description: 'Returns a single plan of the active tenant.',
        summary: 'Get plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions Plans'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the plan', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingPlanResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
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
