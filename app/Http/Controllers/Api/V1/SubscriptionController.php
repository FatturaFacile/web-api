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
use Symfony\Component\HttpFoundation\Response;

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

    public function store(StoreSubscriptionRequest $request): JsonResponse
    {
        return $this->successResponse(
            new SubscriptionResource($this->save->create($request->validated())),
            __('subscriptions.messages.created'),
            Response::HTTP_CREATED
        );
    }

    public function show(string $id): JsonResponse
    {
        $subscription = Subscription::with(['contact', 'items', 'runs'])->findOrFail($id);

        return $this->successResponse(new SubscriptionResource($subscription), __('subscriptions.messages.retrieved'));
    }

    public function update(StoreSubscriptionRequest $request, string $id): JsonResponse
    {
        $subscription = $this->save->update(Subscription::findOrFail($id), $request->validated());

        return $this->successResponse(new SubscriptionResource($subscription), __('subscriptions.messages.updated'));
    }

    public function destroy(string $id): JsonResponse
    {
        Subscription::findOrFail($id)->delete();

        return $this->successResponse(null, __('subscriptions.messages.deleted'));
    }

    public function pause(string $id): JsonResponse
    {
        return $this->transition($id, ['active'], 'paused', 'paused');
    }

    public function resume(string $id): JsonResponse
    {
        return $this->transition($id, ['paused'], 'active', 'resumed');
    }

    public function cancel(string $id): JsonResponse
    {
        return $this->transition($id, ['active', 'paused'], 'cancelled', 'cancelled');
    }

    /**
     * Manually bill every period currently due for this subscription.
     */
    public function bill(string $id): JsonResponse
    {
        $subscription = Subscription::findOrFail($id);
        $runs = $this->billing->execute($subscription);

        return $this->successResponse(
            ['invoices_created' => count($runs), 'invoice_ids' => array_map(fn ($run): ?string => $run->invoice_id, $runs)],
            trans_choice('subscriptions.messages.billed', count($runs))
        );
    }

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
