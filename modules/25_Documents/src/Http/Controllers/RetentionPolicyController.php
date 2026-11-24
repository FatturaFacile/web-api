<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Requests\StoreRetentionPolicyRequest;
use Modules\Documents\Http\Resources\RetentionPolicyResource;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Services\DocumentAccess;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retention rules decide when a document is archived or deleted, so only owners and administrators can change them.
 */
final class RetentionPolicyController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access) {}

    /**
     * List retention policies.
     */
    #[OA\Get(
        path: '/api/v1/dms/retention-policies',
        operationId: 'listDmsRetentionPolicies',
        description: 'Retention rules of the tenant. Periods are chosen by the company: none ships with the application.',
        summary: 'List retention policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 200, description: 'Policies', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsRetentionPolicyResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(RetentionPolicyResource::collection(DmsRetentionPolicy::query()->orderBy('name')->get()));
    }

    /**
     * Create retention policy.
     */
    #[OA\Post(
        path: '/api/v1/dms/retention-policies',
        operationId: 'createDmsRetentionPolicy',
        description: 'Create a retention rule. Owners and administrators only.',
        summary: 'Create retention policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsStoreRetentionPolicyRequest')),
        tags: ['Document Management'],
        responses: [
            new OA\Response(response: 201, description: 'Policy created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsRetentionPolicyResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreRetentionPolicyRequest $request): JsonResponse
    {
        $this->access->assertManager($request->user());

        return $this->successResponse(new RetentionPolicyResource(DmsRetentionPolicy::create($request->validated())), 'Retention policy created', Response::HTTP_CREATED);
    }

    /**
     * Update retention policy.
     */
    #[OA\Put(
        path: '/api/v1/dms/retention-policies/{id}',
        operationId: 'updateDmsRetentionPolicy',
        description: 'Change a retention rule. Owners and administrators only.',
        summary: 'Update retention policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsStoreRetentionPolicyRequest')),
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Policy updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsRetentionPolicyResource')])),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function update(StoreRetentionPolicyRequest $request, string $id): JsonResponse
    {
        $this->access->assertManager($request->user());
        $policy = DmsRetentionPolicy::query()->findOrFail($id);
        $policy->update($request->validated());

        return $this->successResponse(new RetentionPolicyResource($policy), 'Retention policy updated');
    }

    /**
     * Delete retention policy.
     */
    #[OA\Delete(
        path: '/api/v1/dms/retention-policies/{id}',
        operationId: 'deleteDmsRetentionPolicy',
        description: 'Delete a retention rule. Owners and administrators only.',
        summary: 'Delete retention policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Management'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Policy deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->access->assertManager($request->user());
        DmsRetentionPolicy::query()->findOrFail($id)->delete();

        return $this->successResponse(null, 'Retention policy deleted');
    }
}
