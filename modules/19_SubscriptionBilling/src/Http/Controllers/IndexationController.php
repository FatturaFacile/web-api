<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\SubscriptionBilling\Http\Requests\RecordInflationIndexRequest;
use Modules\SubscriptionBilling\Models\IndexationAdjustment;
use Modules\SubscriptionBilling\Models\InflationIndex;
use Modules\SubscriptionBilling\Models\Subscription;
use Modules\SubscriptionBilling\Services\IndexationService;

final class IndexationController extends Controller
{
    public function __construct(
        private readonly IndexationService $indexation,
        private readonly TenantContext $tenantContext
    ) {}

    public function indices(Request $request): JsonResponse
    {
        $query = InflationIndex::query()->orderByDesc('reference_month');

        if ($request->filled('index_type')) {
            $query->where('index_type', $request->query('index_type'));
        }

        return response()->json(['data' => $query->limit(120)->get()]);
    }

    public function recordIndex(RecordInflationIndexRequest $request): JsonResponse
    {
        $data = $request->validated();
        $index = $this->indexation->recordIndex(
            $data['index_type'],
            $data['reference_month'].'-01',
            (float) $data['index_value'],
            $data['base_period'] ?? null,
            $data['source'] ?? null
        );

        return response()->json(['message' => 'Index recorded', 'data' => $index], 201);
    }

    public function adjustments(Request $request): JsonResponse
    {
        $query = IndexationAdjustment::query()->where('tenant_id', $this->tenantId($request))->orderByDesc('effective_date');

        if ($request->filled('subscription_id')) {
            $query->where('subscription_id', $request->query('subscription_id'));
        }

        return response()->json(['data' => $query->paginate(25)]);
    }

    public function preview(Request $request, string $id): JsonResponse
    {
        $subscription = $this->subscription($request, $id);

        try {
            $sheet = $this->indexation->preview($subscription, $request->filled('effective_date') ? Carbon::parse((string) $request->query('effective_date')) : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $sheet]);
    }

    public function apply(Request $request, string $id): JsonResponse
    {
        $subscription = $this->subscription($request, $id);
        $request->validate(['effective_date' => ['nullable', 'date']]);

        try {
            $adjustment = $this->indexation->apply(
                $subscription,
                $request->filled('effective_date') ? Carbon::parse((string) $request->input('effective_date')) : null,
                $request->user()?->id ? (string) $request->user()->id : null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Indexation applied', 'data' => $adjustment], 201);
    }

    public function process(Request $request): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);

        $result = $this->indexation->processDueIndexations(
            $this->tenantId($request),
            $request->filled('as_of') ? Carbon::parse((string) $request->input('as_of')) : null
        );

        return response()->json(['data' => $result]);
    }

    private function subscription(Request $request, string $id): Subscription
    {
        return Subscription::with('items')->where('tenant_id', $this->tenantId($request))->where('id', $id)->firstOrFail();
    }

    private function tenantId(Request $request): string
    {
        $tenant = $this->tenantContext->getTenant();
        if ($tenant) {
            return (string) $tenant->id;
        }

        $user = $request->user();

        return (string) ($user?->current_tenant_id ?? $user?->tenant_id ?? '019318b7-0000-7000-8000-000000000001');
    }
}
