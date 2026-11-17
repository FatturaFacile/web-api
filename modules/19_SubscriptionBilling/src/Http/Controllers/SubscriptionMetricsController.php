<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Services\SubscriptionMetricsService;

final class SubscriptionMetricsController extends Controller
{
    public function __construct(
        private readonly SubscriptionMetricsService $metrics,
        private readonly TenantContext $tenantContext
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'gross_margin_pct' => ['nullable', 'numeric', 'between:1,100'],
        ]);

        return response()->json(['data' => $this->metrics->summary(
            $this->tenantId($request),
            isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            isset($validated['to']) ? Carbon::parse($validated['to']) : null,
            (float) ($validated['gross_margin_pct'] ?? 100.0)
        )]);
    }

    public function trend(Request $request): JsonResponse
    {
        $validated = $request->validate(['months' => ['nullable', 'integer', 'between:1,36']]);

        return response()->json(['data' => $this->metrics->trend($this->tenantId($request), (int) ($validated['months'] ?? 6))]);
    }

    public function churnReasons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $summary = $this->metrics->summary(
            $this->tenantId($request),
            isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            isset($validated['to']) ? Carbon::parse($validated['to']) : null
        );

        return response()->json(['data' => [
            'period' => $summary['period'],
            'churned_subscriptions' => $summary['churned_subscriptions'],
            'reasons' => $summary['churn_reasons'],
        ]]);
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
