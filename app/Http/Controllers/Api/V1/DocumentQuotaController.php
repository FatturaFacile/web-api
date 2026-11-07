<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Tenancy\DocumentQuotaService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DocumentQuotaController extends Controller
{
    use ApiResponse;

    #[OA\Get(path: '/api/v1/settings/document-quota', operationId: 'getDocumentQuota', description: 'Yearly document points of the active tenant: an invoice is worth 1, a commercial document 0.1. `enabled` is false when the tenant has no limit.', summary: 'Document quota usage', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Settings'], responses: [
        new OA\Response(response: 200, description: 'The usage', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function usage(TenantContext $tenantContext, DocumentQuotaService $quota): JsonResponse
    {
        return $this->successResponse($quota->usage($tenantContext->getTenant()), 'Document quota retrieved');
    }

    #[OA\Put(path: '/api/v1/admin/tenants/{id}/document-quota', operationId: 'updateDocumentQuota', description: 'Sets the yearly points of a tenant, for platform administrators only. Null turns the limit off.', summary: 'Set document quota', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'document_quota_points', type: 'number', nullable: true)])), tags: ['Admin'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Quota saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function update(Request $request, string $id, DocumentQuotaService $quota): JsonResponse
    {
        $data = $request->validate(['document_quota_points' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999']]);
        $tenant = Tenant::findOrFail($id);
        $tenant->update($data);

        return $this->successResponse($quota->usage($tenant), 'Document quota saved');
    }
}
