<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\StoreSubscriptionRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Services\LegacySubscriptionBridge;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deprecated recurring billing API. It keeps its request and response contract, but every subscription lives in the
 * subscription engine of modules/19_SubscriptionBilling (sub_subscriptions) and is billed by it through the
 * invoicing services; the successor is /api/v1/sub/contracts.
 */
#[OA\Tag(name: 'Subscriptions', description: 'Deprecated recurring billing API, kept for compatibility on the subscription engine: use /api/v1/sub/contracts')]
class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly LegacySubscriptionBridge $bridge) {}

    /**
     * List paginated subscriptions with optional status filter.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions',
        operationId: 'legacyListSubscriptions',
        description: 'Deprecated: use GET /api/v1/sub/contracts. Paginated subscriptions of the active company.',
        summary: 'List subscriptions (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscriptions retrieved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegacySubscription'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot read subscriptions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $this->bridge->paginate($request->filled('status') ? (string) $request->query('status') : null, min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: collect($subscriptions->items())->map(fn (Subscription $subscription): array => $this->bridge->present($subscription))->all(),
            message: __('subscriptions.messages.list'),
            meta: ['current_page' => $subscriptions->currentPage(), 'last_page' => $subscriptions->lastPage(), 'per_page' => $subscriptions->perPage(), 'total' => $subscriptions->total()]
        );
    }

    /**
     * Create a subscription billed by the subscription engine.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions',
        operationId: 'legacyCreateSubscription',
        description: 'Deprecated: use POST /api/v1/sub/contracts. Creates a subscription whose charges are its items.',
        summary: 'Create subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LegacySubscriptionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Subscription created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'The role cannot write subscriptions', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->bridge->create($request->validated(), $this->actorId($request));

        return $this->successResponse($this->bridge->present($subscription, true), __('subscriptions.messages.created'), Response::HTTP_CREATED);
    }

    /**
     * Retrieve a subscription with its items and billed periods.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions/{id}',
        operationId: 'legacyGetSubscription',
        description: 'Deprecated: use GET /api/v1/sub/contracts/{id}.',
        summary: 'Get subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Subscription details', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        return $this->successResponse($this->bridge->present(Subscription::query()->findOrFail($id), true), __('subscriptions.messages.retrieved'));
    }

    /**
     * Update the terms or replace the charges (future periods only).
     */
    #[OA\Put(
        path: '/api/v1/subscriptions/{id}',
        operationId: 'legacyUpdateSubscription',
        description: 'Deprecated: use the plan, quantity and cancellation actions of /api/v1/sub/contracts/{id}. Replacing the items affects the periods not billed yet.',
        summary: 'Update subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/LegacySubscriptionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Subscription updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreSubscriptionRequest $request, string $id): JsonResponse
    {
        Subscription::query()->findOrFail($id);
        $subscription = $this->bridge->update($id, $request->validated(), $this->actorId($request));

        return $this->successResponse($this->bridge->present($subscription, true), __('subscriptions.messages.updated'));
    }

    /**
     * Soft-delete a subscription (its invoices and history are kept).
     */
    #[OA\Delete(
        path: '/api/v1/subscriptions/{id}',
        operationId: 'legacyDeleteSubscription',
        description: 'Deprecated: cancel through POST /api/v1/sub/contracts/{id}/cancel. The subscription is soft-deleted; its invoices stay.',
        summary: 'Delete subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Subscription deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        Subscription::query()->findOrFail($id)->delete();

        return $this->successResponse(null, __('subscriptions.messages.deleted'));
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/pause',
        operationId: 'legacyPauseSubscription',
        description: 'Deprecated: use POST /api/v1/sub/contracts/{id}/pause.',
        summary: 'Pause subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Subscription paused', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function pause(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'pause', 'paused');
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/resume',
        operationId: 'legacyResumeSubscription',
        description: 'Deprecated: use POST /api/v1/sub/contracts/{id}/reactivate.',
        summary: 'Resume subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Subscription resumed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function resume(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'resume', 'resumed');
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/cancel',
        operationId: 'legacyCancelSubscription',
        description: 'Deprecated: use POST /api/v1/sub/contracts/{id}/cancel (reasons, end of term, refunds). Cancels at once without refund.',
        summary: 'Cancel subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Subscription cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/LegacySubscription')])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Invalid state transition', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function cancel(Request $request, string $id): JsonResponse
    {
        return $this->transition($request, $id, 'cancel', 'cancelled');
    }

    /**
     * Manually bill every period currently due for this subscription.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/bill',
        operationId: 'legacyBillSubscription',
        description: 'Deprecated: use POST /api/v1/sub/contracts/{id}/bill. Issues one invoice per due period through the invoicing services; idempotent.',
        summary: 'Bill subscription (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Billing executed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'invoices_created', type: 'integer'), new OA\Property(property: 'invoice_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))], type: 'object')])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function bill(Request $request, string $id): JsonResponse
    {
        $result = $this->bridge->bill($id, $this->actorId($request));

        return $this->successResponse($result, trans_choice('subscriptions.messages.billed', $result['invoices_created']));
    }

    #[OA\Get(
        path: '/api/v1/subscriptions/{id}/runs',
        operationId: 'legacyListSubscriptionRuns',
        description: 'Deprecated: use GET /api/v1/sub/contracts/{id}/invoices.',
        summary: 'List subscription billing runs (deprecated)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Subscriptions'],
        deprecated: true,
        parameters: [new OA\Parameter(ref: '#/components/parameters/LegacySubscriptionId')],
        responses: [
            new OA\Response(response: 200, description: 'Billing runs', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LegacySubscriptionRun'))])),
            new OA\Response(response: 404, description: 'Subscription not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function runs(string $id): JsonResponse
    {
        return $this->successResponse($this->bridge->runs(Subscription::query()->findOrFail($id)), __('subscriptions.messages.runs'));
    }

    private function transition(Request $request, string $id, string $action, string $event): JsonResponse
    {
        $subscription = $this->bridge->transition($id, $action, $this->actorId($request));

        return $this->successResponse($this->bridge->present($subscription), __("subscriptions.messages.{$event}"));
    }

    private function actorId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
