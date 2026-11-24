<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\LeaveManagement\Http\Controllers\Concerns\ResolvesLeaveContext;
use Modules\LeaveManagement\Http\Requests\SaveLeaveEntitlementPolicyRequest;
use Modules\LeaveManagement\Http\Resources\LeaveEntitlementPolicyResource;
use Modules\LeaveManagement\Models\LeaveEntitlementPolicy;
use OpenApi\Attributes as OA;

/**
 * CRUD endpoints for CH/IT Leave Entitlement Policies (Ferie, ROL, Ex-Festività, Art. 329a/329b CO, IJM/KTG, SUVA/INAIL).
 */
final class LeaveEntitlementPolicyController extends Controller
{
    use ApiResponse;
    use ResolvesLeaveContext;

    #[OA\Get(
        path: '/api/v1/leave-management/policies',
        operationId: 'leaveMgmtListPolicies',
        summary: 'List leave entitlement policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 200, description: 'Entitlement policies')]
    )]
    public function index(Request $request): JsonResponse
    {
        $country = $request->query('country');
        $leaveType = $request->query('leave_type');

        $policies = LeaveEntitlementPolicy::query()
            ->where('tenant_id', $this->tenantId())
            ->when(is_string($country) && $country !== '', static fn ($q) => $q->where('country', strtoupper($country)))
            ->when(is_string($leaveType) && $leaveType !== '', static fn ($q) => $q->where('leave_type', $leaveType))
            ->orderBy('country')
            ->orderBy('leave_type')
            ->orderBy('code')
            ->get();

        return $this->successResponse(LeaveEntitlementPolicyResource::collection($policies));
    }

    #[OA\Post(
        path: '/api/v1/leave-management/policies',
        operationId: 'leaveMgmtStorePolicy',
        summary: 'Create a leave entitlement policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        responses: [new OA\Response(response: 201, description: 'Entitlement policy created')]
    )]
    public function store(SaveLeaveEntitlementPolicyRequest $request): JsonResponse
    {
        $policy = LeaveEntitlementPolicy::query()->create(array_merge(
            $request->validated(),
            ['tenant_id' => $this->tenantId()]
        ));

        return $this->successResponse(new LeaveEntitlementPolicyResource($policy), __('leave_management.messages.policy_created'), 201);
    }

    #[OA\Put(
        path: '/api/v1/leave-management/policies/{id}',
        operationId: 'leaveMgmtUpdatePolicy',
        summary: 'Update a leave entitlement policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Entitlement policy updated')]
    )]
    public function update(SaveLeaveEntitlementPolicyRequest $request, string $id): JsonResponse
    {
        $policy = LeaveEntitlementPolicy::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $policy->update($request->validated());

        return $this->successResponse(new LeaveEntitlementPolicyResource($policy->fresh() ?? $policy), __('leave_management.messages.policy_updated'));
    }

    #[OA\Delete(
        path: '/api/v1/leave-management/policies/{id}',
        operationId: 'leaveMgmtDeletePolicy',
        summary: 'Delete a leave entitlement policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Leave Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Entitlement policy deleted')]
    )]
    public function destroy(string $id): JsonResponse
    {
        Gate::authorize('manageConfiguration', LeaveEntitlementPolicy::class);
        $policy = LeaveEntitlementPolicy::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
        $policy->delete();

        return $this->successResponse(null, __('leave_management.messages.policy_deleted'));
    }
}
