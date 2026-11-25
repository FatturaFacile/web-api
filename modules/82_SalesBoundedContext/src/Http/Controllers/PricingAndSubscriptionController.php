<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\SalesBoundedContext\Application\Commands\EvaluatePricingAndMarginGuardrailCommand;
use Modules\SalesBoundedContext\Application\Commands\EvaluatePricingAndMarginGuardrailHandler;
use Modules\SalesBoundedContext\Application\Commands\TransitionSubscriptionLifecycleCommand;
use Modules\SalesBoundedContext\Application\Commands\TransitionSubscriptionLifecycleHandler;
use Modules\SalesBoundedContext\Http\Controllers\Concerns\InteractsWithSalesBoundedContext;
use Modules\SalesBoundedContext\Http\Requests\EvaluatePricingGuardrailRequest;
use Modules\SalesBoundedContext\Http\Requests\SubscriptionActionRequest;
use Modules\SalesBoundedContext\Http\Resources\SbcRecordResource;
use Modules\SalesBoundedContext\Models\SbcPricingGuardrail;
use Modules\SalesBoundedContext\Models\SbcRecurringSubscription;
use OpenApi\Attributes as OA;

final class PricingAndSubscriptionController extends Controller
{
    use InteractsWithSalesBoundedContext;

    public function __construct(
        private readonly EvaluatePricingAndMarginGuardrailHandler $pricingHandler,
        private readonly TransitionSubscriptionLifecycleHandler $subscriptionHandler,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/pricing-guardrails',
        operationId: 'listSbcPricingGuardrails',
        summary: 'List pricing rules, cascading discounts & margin guardrails (Mod 038)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Pricing guardrails')]
    )]
    public function pricingIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcPricingGuardrail::class);

        return SbcRecordResource::collection(
            SbcPricingGuardrail::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/pricing-guardrails',
        operationId: 'evaluateSbcPricingGuardrail',
        summary: 'Evaluate cascading discounts, promotions & minimum margin guardrail (Mod 038)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Pricing evaluated')]
    )]
    public function pricingStore(EvaluatePricingGuardrailRequest $request): JsonResponse
    {
        $this->authorize('create', SbcPricingGuardrail::class);
        $rule = $this->pricingHandler->handle(new EvaluatePricingAndMarginGuardrailCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($rule))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/pricing-guardrails/{id}/approve-override',
        operationId: 'approveSbcMarginGuardrailOverride',
        summary: 'Approve a margin guardrail breach override (Mod 038)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Override approved')]
    )]
    public function pricingApproveOverride(Request $request, string $id): SbcRecordResource
    {
        $rule = SbcPricingGuardrail::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('approveMarginOverride', $rule);

        return new SbcRecordResource(
            $this->pricingHandler->handle(new EvaluatePricingAndMarginGuardrailCommand(
                $this->tenantId(),
                ['action' => 'approve_override', 'reason' => (string) $request->input('reason', 'Approved')],
                $id
            ))
        );
    }

    #[OA\Get(
        path: '/api/v1/sales-bounded-context/subscriptions',
        operationId: 'listSbcSubscriptions',
        summary: 'List recurring subscriptions with pro-rata & ISTAT/LIK indexation (Mod 039)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Subscriptions')]
    )]
    public function subscriptionsIndex(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SbcRecurringSubscription::class);

        return SbcRecordResource::collection(
            SbcRecurringSubscription::query()->where('tenant_id', $this->tenantId())->latest()->paginate($this->perPage())
        );
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/subscriptions',
        operationId: 'storeSbcSubscription',
        summary: 'Create a recurring subscription with pro-rata & MRR/ARR tracking (Mod 039)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 201, description: 'Subscription created')]
    )]
    public function subscriptionsStore(SubscriptionActionRequest $request): JsonResponse
    {
        $this->authorize('create', SbcRecurringSubscription::class);
        $sub = $this->subscriptionHandler->handle(new TransitionSubscriptionLifecycleCommand($this->tenantId(), $request->validated()));

        return (new SbcRecordResource($sub))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/sales-bounded-context/subscriptions/{id}/transition',
        operationId: 'transitionSbcSubscription',
        summary: 'Transition subscription lifecycle (pause/upgrade/downgrade/cancel/ISTAT-LIK revalue) (Mod 039)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Sales Bounded Context'],
        responses: [new OA\Response(response: 200, description: 'Subscription transitioned')]
    )]
    public function subscriptionsTransition(SubscriptionActionRequest $request, string $id): SbcRecordResource
    {
        $sub = SbcRecurringSubscription::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $this->authorize('update', $sub);

        return new SbcRecordResource(
            $this->subscriptionHandler->handle(new TransitionSubscriptionLifecycleCommand($this->tenantId(), $request->validated(), $id))
        );
    }
}
