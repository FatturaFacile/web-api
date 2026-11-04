<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Subscriptions\RunSubscriptionBilling;
use App\Actions\Subscriptions\SaveSubscription;
use App\Http\Controllers\Controller;
use App\Http\Requests\Subscriptions\StoreSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Subscription;
use App\Services\Sales\DocumentEventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

#[OA\Tag(name: 'Subscriptions', description: 'Recurring billing subscriptions, billing cycles, pause/resume, and invoices')]
class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SaveSubscription $save,
        private readonly RunSubscriptionBilling $billing,
        private readonly DocumentEventRecorder $events,
    ) {}

    /**
     * List paginated subscriptions with optional status filter.
     */
    #[OA\Get(
        path: '/api/v1/subscriptions',
        summary: 'List subscriptions',
        description: 'Get paginated list of subscriptions with optional status filter',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscriptions retrieved successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = Subscription::with('contact')->latest('start_date');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $subscriptions = $query->paginate(min(100, max(5, (int) $request->query('per_page', 15))));

        return $this->successResponse(
            data: SubscriptionResource::collection($subscriptions),
            message: __('subscriptions.messages.list'),
            meta: ['current_page' => $subscriptions->currentPage(), 'last_page' => $subscriptions->lastPage(), 'per_page' => $subscriptions->perPage(), 'total' => $subscriptions->total()]
        );
    }

    #[OA\Post(
        path: '/api/v1/subscriptions',
        summary: 'Create subscription',
        description: 'Create a new recurring subscription plan',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['contact_id', 'frequency', 'start_date'],
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'frequency', type: 'string'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Subscription created successfully'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        return $this->successResponse(
            new SubscriptionResource($this->save->create($request->validated())),
            __('subscriptions.messages.created'),
            Response::HTTP_CREATED
        );
    }

    #[OA\Get(
        path: '/api/v1/subscriptions/{id}',
        summary: 'Get subscription',
        description: 'Retrieve details, items, and billing runs for a subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscription details retrieved'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $subscription = Subscription::with(['contact', 'items', 'runs'])->findOrFail($id);

        return $this->successResponse(new SubscriptionResource($subscription), __('subscriptions.messages.retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/subscriptions/{id}',
        summary: 'Update subscription',
        description: 'Update subscription terms, items, or parameters',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'frequency', type: 'string'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Subscription updated successfully'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 422, description: 'Validation error'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function update(StoreSubscriptionRequest $request, string $id): JsonResponse
    {
        $subscription = $this->save->update(Subscription::findOrFail($id), $request->validated());

        return $this->successResponse(new SubscriptionResource($subscription), __('subscriptions.messages.updated'));
    }

    #[OA\Delete(
        path: '/api/v1/subscriptions/{id}',
        summary: 'Delete subscription',
        description: 'Delete a subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscription deleted successfully'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        Subscription::findOrFail($id)->delete();

        return $this->successResponse(null, __('subscriptions.messages.deleted'));
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/pause',
        summary: 'Pause subscription',
        description: 'Pause an active subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscription paused'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function pause(string $id): JsonResponse
    {
        return $this->transition($id, ['active'], 'paused', 'paused');
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/resume',
        summary: 'Resume subscription',
        description: 'Resume a paused subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscription resumed'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function resume(string $id): JsonResponse
    {
        return $this->transition($id, ['paused'], 'active', 'resumed');
    }

    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/cancel',
        summary: 'Cancel subscription',
        description: 'Cancel an active or paused subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subscription cancelled'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 422, description: 'Invalid state transition'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        return $this->transition($id, ['active', 'paused'], 'cancelled', 'cancelled');
    }

    /**
     * Manually bill every period currently due for this subscription.
     */
    #[OA\Post(
        path: '/api/v1/subscriptions/{id}/bill',
        summary: 'Bill subscription',
        description: 'Manually generate invoices for all due periods of this subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Billing executed successfully'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function bill(string $id): JsonResponse
    {
        $subscription = Subscription::findOrFail($id);
        $runs = $this->billing->execute($subscription);

        return $this->successResponse(
            ['invoices_created' => count($runs), 'invoice_ids' => array_map(fn ($run): ?string => $run->invoice_id, $runs)],
            trans_choice('subscriptions.messages.billed', count($runs))
        );
    }

    #[OA\Get(
        path: '/api/v1/subscriptions/{id}/runs',
        summary: 'List subscription billing runs',
        description: 'Get historical billing executions for a subscription',
        tags: ['Subscriptions'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Billing runs retrieved'),
            new OA\Response(response: 404, description: 'Subscription not found'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function runs(string $id): JsonResponse
    {
        $runs = Subscription::findOrFail($id)->runs()->orderByDesc('period_start')->get();

        return $this->successResponse($runs->map(fn ($run): array => $run->toArray())->all(), __('subscriptions.messages.runs'));
    }

    /**
     * @param  array<int, string>  $allowedFrom
     */
    private function transition(string $id, array $allowedFrom, string $to, string $event): JsonResponse
    {
        $subscription = Subscription::findOrFail($id);

        if (! in_array($subscription->status, $allowedFrom, true)) {
            throw ValidationException::withMessages(['status' => __('subscriptions.invalid_transition', ['status' => $subscription->status, 'action' => __("subscriptions.actions.{$event}")])]);
        }

        $subscription->update(['status' => $to]);
        $this->events->record($subscription, $event);

        return $this->successResponse(new SubscriptionResource($subscription), __("subscriptions.messages.{$event}"));
    }
}
