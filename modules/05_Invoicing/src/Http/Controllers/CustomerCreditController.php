<?php

declare(strict_types=1);

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Actions\EvaluateCustomerCreditAction;
use Modules\Invoicing\Actions\ReleaseSalesOrderHoldAction;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Http\Requests\UpdateCreditLimitRequest;
use Modules\Invoicing\Http\Resources\CustomerCreditProfileResource;
use Modules\Invoicing\Models\SalesOrderHold;

final class CustomerCreditController extends Controller
{
    public function show(string $customerId, EvaluateCustomerCreditAction $action, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $profile = $action->execute($customerId, $tenantId);

        return response()->json(new CustomerCreditProfileResource($profile));
    }

    public function updateLimit(
        UpdateCreditLimitRequest $request,
        CustomerCreditServiceInterface $service,
        TenantContext $tenantContext
    ): JsonResponse {
        $validated = $request->validated();
        $tenantId = $tenantContext->getTenantId();
        $userId = (string) ($request->user()?->id ?? 'system');

        $profile = $service->updateCreditLimit(
            customerId: (string) $validated['customer_id'],
            newLimit: (float) $validated['credit_limit'],
            userId: $userId,
            tenantId: $tenantId
        );

        return response()->json(new CustomerCreditProfileResource($profile));
    }

    public function releaseHold(string $holdId, ReleaseSalesOrderHoldAction $action, Request $request): JsonResponse
    {
        $hold = SalesOrderHold::findOrFail($holdId);
        $userId = (string) ($request->user()?->id ?? 'system');
        $notes = $request->input('notes');

        $released = $action->execute($hold, $userId, $notes);

        return response()->json([
            'message' => 'Hold released successfully',
            'hold_id' => $released->id,
            'status' => $released->status,
        ]);
    }
}
