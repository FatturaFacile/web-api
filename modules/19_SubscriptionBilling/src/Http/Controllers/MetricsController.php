<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Contracts\SubscriptionForecastingServiceInterface;
use Modules\SubscriptionBilling\Services\SubscriptionMetricsService;
use OpenApi\Attributes as OA;

/**
 * SaaS metrics and the cash forecast of the subscriptions of the active company.
 */
final class MetricsController extends Controller
{
    public function __construct(
        private readonly SubscriptionMetricsService $metrics,
        private readonly SubscriptionForecastingServiceInterface $forecasting,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * MRR, ARR, MRR movements, churn, ARPA and LTV.
     */
    #[OA\Get(path: '/api/v1/subscriptions/metrics', operationId: 'getSubscriptionsMetrics', summary: 'Subscription metrics', description: 'MRR and ARR of the active and past-due subscriptions, MRR movements of the window from the subscription versions, customer and revenue churn, ARPA and LTV (ARPA / monthly churn).', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Metrics'], parameters: [
        new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'window_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 30, minimum: 1, maximum: 366)),
    ], responses: [
        new OA\Response(response: 200, description: 'Metrics', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingMetrics')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function metrics(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'window_days' => ['nullable', 'integer', 'min:1', 'max:366']]);
        $asOf = isset($data['as_of']) ? CarbonImmutable::parse($data['as_of']) : CarbonImmutable::today();

        return response()->json(['data' => $this->metrics->metrics((string) $this->tenantContext->getTenantId(), $asOf, (int) ($data['window_days'] ?? 30))]);
    }

    /**
     * Net amounts the live subscriptions will invoice in the coming months.
     */
    #[OA\Get(path: '/api/v1/subscriptions/forecast', operationId: 'getSubscriptionsForecast', summary: 'Recurring revenue forecast', description: 'Net amounts the live subscriptions will invoice per month at their current prices, until their term ends without renewal or their cancellation takes effect; no growth is assumed.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Metrics'], parameters: [
        new OA\Parameter(name: 'months', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 12, minimum: 1, maximum: 36)),
    ], responses: [
        new OA\Response(response: 200, description: 'Forecast', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingForecast')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function forecast(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:36']]);

        return response()->json(['data' => $this->forecasting->generateCashForecast((string) $this->tenantContext->getTenantId(), (int) ($data['months'] ?? 12))]);
    }
}
