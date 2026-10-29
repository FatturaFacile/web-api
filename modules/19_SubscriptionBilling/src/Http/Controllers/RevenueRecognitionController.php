<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\SubscriptionBilling\Actions\RecognizeRevenueAction;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Http\Requests\RecognizeRevenueRequest;
use Modules\SubscriptionBilling\Http\Resources\RevenueContractResource;
use Modules\SubscriptionBilling\Models\RevenueContract;

final class RevenueRecognitionController extends Controller
{
    public function __construct(
        private readonly RecognizeRevenueAction $recognizeAction,
        private readonly TenantContext $tenantContext
    ) {}

    public function listContracts(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $query = RevenueContract::where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $contracts = $query->orderByDesc('inception_date')->paginate(25);

        return response()->json([
            'data' => RevenueContractResource::collection($contracts),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
            ],
        ]);
    }

    public function showContract(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $contract = RevenueContract::with(['performanceObligations.revenueSchedules'])
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();

        return response()->json([
            'data' => new RevenueContractResource($contract),
        ]);
    }

    public function recognizeBatch(RecognizeRevenueRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecognizeRevenueDTO(
            tenantId: $tenantId,
            companyId: $tenantId,
            periodDate: $validated['period_date'] ?? null,
            executedBy: $request->user()?->id
        );

        $result = $this->recognizeAction->execute($dto);

        return response()->json([
            'message' => 'Revenue recognized successfully',
            'data' => $result,
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
