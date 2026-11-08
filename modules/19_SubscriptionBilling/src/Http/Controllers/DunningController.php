<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Modules\SubscriptionBilling\Http\Requests\DunningPolicyRequest;
use Modules\SubscriptionBilling\Http\Resources\DunningPolicyResource;
use Modules\SubscriptionBilling\Models\DunningPolicy;
use Modules\SubscriptionBilling\Services\DunningService;
use OpenApi\Attributes as OA;

/**
 * The dunning policy of the active company and the manual dunning run.
 */
final class DunningController extends Controller
{
    public function __construct(
        private readonly DunningService $dunning,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The policy in force (the built-in defaults when the company has none).
     */
    #[OA\Get(path: '/api/v1/subscriptions/dunning-policy', operationId: 'getSubscriptionsDunningPolicy', summary: 'Get dunning policy', description: 'The dunning policy of the company; the built-in defaults (3/10/25/45 days, 3 retries every 3 days) when it has none.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Dunning'], responses: [
        new OA\Response(response: 200, description: 'Dunning policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnauthenticated', response: 401),
    ])]
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->dunning->policy((string) $this->tenantContext->getTenantId())]);
    }

    /**
     * Creates or replaces the default policy of the company.
     */
    #[OA\Put(path: '/api/v1/subscriptions/dunning-policy', operationId: 'updateSubscriptionsDunningPolicy', summary: 'Save dunning policy', description: 'Creates or replaces the default dunning policy of the company: increasing escalation days and the collection retries.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Dunning'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SubscriptionBillingDunningPolicyRequest')), responses: [
        new OA\Response(response: 200, description: 'Dunning policy', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SubscriptionBillingDunningPolicyResource')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingUnprocessable', response: 422),
    ])]
    public function update(DunningPolicyRequest $request): JsonResponse
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $policy = DunningPolicy::query()->where('is_default', true)->first() ?? new DunningPolicy(['tenant_id' => $tenantId, 'company_id' => $tenantId, 'is_default' => true]);
        $policy->fill([...$request->validated(), 'is_default' => true])->save();

        return response()->json(['message' => 'Dunning policy saved', 'data' => (new DunningPolicyResource($policy->refresh()))->resolve()]);
    }

    /**
     * Runs the dunning of the company now (also run daily by subscriptions:process).
     */
    #[OA\Post(path: '/api/v1/subscriptions/dunning/run', operationId: 'runSubscriptionsDunning', summary: 'Run dunning', description: 'Announces the due collection retries, escalates the overdue subscription invoices (reminder, formal notice, suspension, cancellation) and closes the cases of the paid ones.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Subscriptions Dunning'], responses: [
        new OA\Response(response: 200, description: 'Dunning outcome', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'retries_announced', type: 'integer'), new OA\Property(property: 'escalations', type: 'integer'), new OA\Property(property: 'resolved', type: 'integer'), new OA\Property(property: 'errors', type: 'array', items: new OA\Items(type: 'object'))], type: 'object')])),
        new OA\Response(ref: '#/components/responses/SubscriptionBillingForbidden', response: 403),
    ])]
    public function run(): JsonResponse
    {
        return response()->json(['message' => 'Dunning executed', 'data' => $this->dunning->processDunning((string) $this->tenantContext->getTenantId())]);
    }
}
