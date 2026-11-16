<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Access\AccessAudit;
use App\Services\Access\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * A platform administrator sets the subscription plan of a tenant and, when needed, its own user and custom role limits.
 */
class TenantPlanController extends Controller
{
    use ApiResponse;

    #[OA\Put(path: '/api/v1/admin/tenants/{id}/plan', operationId: 'updateTenantPlan', description: 'Sets the plan code and optional overrides of the user and custom role limits; null removes an override.', summary: 'Set the plan of a tenant', security: [['bearerAuth' => []]], tags: ['Admin'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Plan saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 403, description: 'Platform administrators only', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse'))])]
    public function update(Request $request, string $id, PlanLimits $limits): JsonResponse
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->findOrFail($id);

        $data = $request->validate([
            'plan_code' => ['sometimes', 'string', Rule::in(array_keys((array) config('access_plans.plans')))],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_custom_roles' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $old = $tenant->only(['plan_code', 'max_users', 'max_custom_roles']);
        $tenant->forceFill($data)->save();

        AccessAudit::record((string) $tenant->id, AccessAudit::TypePlan, (string) $tenant->id, 'plan.updated', $old, $tenant->only(['plan_code', 'max_users', 'max_custom_roles']));

        return $this->successResponse(data: $limits->describe($tenant), message: 'Plan saved');
    }
}
