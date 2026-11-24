<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\SubscriptionBilling\Http\Requests\CreatePlanRequest;
use Modules\SubscriptionBilling\Http\Requests\UpdatePlanRequest;
use Modules\SubscriptionBilling\Http\Resources\PlanResource;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Support\Money;
use OpenApi\Attributes as OA;

/**
 * Plans of the active company with their price tiers and metered features. A plan's price model and tiers are fixed
 * once created (new prices are new plans, reached by upgrade or downgrade); names, availability, VAT rate, trial,
 * renewal increase and accounts can change.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Paginated plans, filtered by interval and availability.
     */
    #[OA\Get(path: '/api/v1/subscriptions/plans', operationId: 'listSubscriptionsPlans', summary: 'List plans', description: 'Paginated plans of the active company.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Plans'], parameters: [
        new OA\Parameter(name: 'interval', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPage'),
    ], responses: [
        new OA\Response(response: 200, description: 'Plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/SubscriptionBillingPlanResource')), new OA\Property(property: 'meta', ref: '#/components/schemas/SubscriptionBillingPaginationMeta')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
    ])]
    public function index(Request $request): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->with(['pricingTiers', 'features'])
            ->when($request->filled('interval'), fn ($query) => $query->where('billing_interval', (string) $request->query('interval')))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderBy('name')
            ->gridPaginate(RequestedPerPage::resolve(25));

        return response()->json([
            'data' => PlanResource::collection($plans->getCollection())->resolve(),
            'meta' => ['current_page' => $plans->currentPage(), 'last_page' => $plans->lastPage(), 'total' => $plans->total()],
        ]);
    }

    /**
     * Creates a plan with its tiers and features.
     */
    #[OA\Post(path: '/api/v1/subscriptions/plans', operationId: 'createSubscriptionsPlan', summary: 'Create plan', description: 'Creates a plan: flat, per seat, tiered (graduated), volume or metered pricing, with trial, setup fee, VAT rate, renewal increase and ledger accounts.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Plans'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingCreatePlanRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingPlan', response: 201),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function store(CreatePlanRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $data = $request->validated();

        $plan = DB::transaction(function () use ($tenantId, $data): SubscriptionPlan {
            $plan = SubscriptionPlan::create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'product_id' => $data['product_id'] ?? null,
                'plan_code' => $data['plan_code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'billing_interval' => $data['billing_interval'],
                'interval_count' => (int) ($data['interval_count'] ?? 1),
                'billing_alignment' => $data['billing_alignment'] ?? 'anniversary',
                'pricing_model' => ($data['pricing_model'] ?? 'flat_fee') === 'per_seat' ? 'per_unit' : ($data['pricing_model'] ?? 'flat_fee'),
                'currency' => strtoupper($data['currency'] ?? 'EUR'),
                'setup_fee' => Money::round2((string) ($data['setup_fee'] ?? '0')),
                'base_price' => Money::round4((string) $data['base_price']),
                'tax_rate' => isset($data['tax_rate']) ? Money::round2((string) $data['tax_rate']) : null,
                'trial_period_days' => (int) ($data['trial_period_days'] ?? 0),
                'min_commitment_cycles' => (int) ($data['min_commitment_cycles'] ?? 1),
                'renewal_price_increase_percent' => Money::round((string) ($data['renewal_price_increase_percent'] ?? '0'), 4),
                'revenue_account_id' => $data['revenue_account_id'] ?? null,
                'deferred_revenue_account_id' => $data['deferred_revenue_account_id'] ?? null,
                'is_active' => true,
                'translations' => $data['translations'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            $tiers = collect($data['tiers'] ?? [])->sortBy(fn (array $tier): float => (float) $tier['from_quantity'])->values();

            foreach ($tiers as $index => $tier) {
                PricingTier::create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'tier_number' => $index + 1,
                    'from_quantity' => Money::round4((string) $tier['from_quantity']),
                    'to_quantity' => isset($tier['to_quantity']) ? Money::round4((string) $tier['to_quantity']) : null,
                    'unit_price' => Money::round4((string) $tier['unit_price']),
                    'flat_fee' => Money::round2((string) ($tier['flat_fee'] ?? '0')),
                ]);
            }

            foreach ($data['features'] ?? [] as $feature) {
                PlanFeature::create([
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'feature_code' => $feature['feature_code'],
                    'name' => $feature['name'],
                    'metric_type' => $feature['metric_type'] ?? 'metered_quota',
                    'included_quantity' => Money::round4((string) ($feature['included_quantity'] ?? '0')),
                    'overage_unit_price' => Money::round4((string) ($feature['overage_unit_price'] ?? '0')),
                    'is_active' => true,
                ]);
            }

            return $plan;
        });

        return response()->json(['message' => 'Subscription plan created successfully', 'data' => (new PlanResource($plan->load(['pricingTiers', 'features'])))->resolve()], 201);
    }

    /**
     * A plan with its tiers and features.
     */
    #[OA\Get(path: '/api/v1/subscriptions/plans/{id}', operationId: 'getSubscriptionsPlan', summary: 'Get plan', description: 'A plan of the active company with its tiers and features.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Plans'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPlanId')], responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingPlan', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
    ])]
    public function show(string $id): JsonResponse
    {
        return response()->json(['message' => 'Plan retrieved', 'data' => (new PlanResource(SubscriptionPlan::query()->with(['pricingTiers', 'features'])->findOrFail($id)))->resolve()]);
    }

    /**
     * Updates the changeable fields of a plan.
     */
    #[OA\Put(path: '/api/v1/subscriptions/plans/{id}', operationId: 'updateSubscriptionsPlan', summary: 'Update plan', description: 'Changes name, description, availability, VAT rate of the next invoices, trial, renewal increase and accounts. Prices and tiers do not change: create a new plan.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Plans'], parameters: [new OA\Parameter(ref: '#/components/parameters/SubscriptionBillingPlanId')], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingUpdatePlanRequest')), responses: [
        new OA\Response(ref: '#/components/responses/SubscriptionBillingPlan', response: 200),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function update(UpdatePlanRequest $request, string $id): JsonResponse
    {
        $plan = SubscriptionPlan::query()->findOrFail($id);
        $data = $request->validated();

        foreach (['tax_rate' => 2, 'renewal_price_increase_percent' => 4] as $field => $scale) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $data[$field] = Money::round((string) $data[$field], $scale);
            }
        }

        $plan->update($data);

        return response()->json(['message' => 'Subscription plan updated successfully', 'data' => (new PlanResource($plan->load(['pricingTiers', 'features'])))->resolve()]);
    }
}
