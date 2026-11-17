<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTenantPlanRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\AccessAudit;
use App\Services\Tenancy\AccessPlanLimits;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * The plan of a tenant and its user / custom role limits, for platform administrators.
 */
class TenantPlanController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AccessPlanLimits $limits,
        private readonly AccessAudit $audit,
    ) {}

    #[OA\Put(path: '/api/v1/admin/tenants/{id}/plan', operationId: 'updateTenantPlan', description: 'Sets the plan of a tenant and its optional limits of users and custom roles, for platform administrators only. A null limit removes the override and the plan decides.', summary: 'Set the plan of a tenant', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'plan_code', type: 'string', example: 'standard', enum: ['starter', 'standard', 'professional', 'enterprise']),
        new OA\Property(property: 'max_users', type: 'integer', nullable: true),
        new OA\Property(property: 'max_custom_roles', type: 'integer', nullable: true),
    ])), tags: ['Admin'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Plan saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(UpdateTenantPlanRequest $request, string $id): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($id);
        $change = $this->limits->update($tenant, $request->validated());

        $this->audit->record('access.plan.changed', (string) $tenant->id, Tenant::class, (string) $tenant->id, $change['before'], $change['after']);

        return $this->successResponse(data: $this->limits->describe($tenant), message: 'Plan saved');
    }
}
