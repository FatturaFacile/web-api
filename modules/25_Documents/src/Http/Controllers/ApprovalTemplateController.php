<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Documents\Http\Requests\SaveApprovalTemplateRequest;
use Modules\Documents\Http\Resources\ApprovalTemplateResource;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentApprovalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approval templates: the ordered steps a document walks to be approved. Owners and administrators manage them.
 */
final class ApprovalTemplateController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access, private readonly DocumentApprovalService $approvals) {}

    /**
     * List approval templates.
     */
    #[OA\Get(
        path: '/api/v1/dms/approval-templates',
        operationId: 'listDmsApprovalTemplates',
        description: 'Approval templates of the company with their ordered steps. Filter with `active=1` for the templates that can start a request.',
        summary: 'List approval templates',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [
            new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsApprovalTemplateResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $templates = DmsApprovalTemplate::query()
            ->with('steps')
            ->when(request()->boolean('active'), static fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get();

        return $this->successResponse(ApprovalTemplateResource::collection($templates));
    }

    /**
     * Create an approval template.
     */
    #[OA\Post(
        path: '/api/v1/dms/approval-templates',
        operationId: 'createDmsApprovalTemplate',
        description: 'Create a template with 1 to 10 ordered steps. A step is decided by one named member (`approver_type` user) or by any member with a role (`approver_type` role). Owners and administrators only.',
        summary: 'Create an approval template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsSaveApprovalTemplateRequest')),
        tags: ['Document Approvals'],
        responses: [
            new OA\Response(response: 201, description: 'Template created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalTemplateResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveApprovalTemplateRequest $request): JsonResponse
    {
        $this->access->assertManager($request->user());

        return $this->successResponse(new ApprovalTemplateResource($this->approvals->createTemplate($request->validated())), 'Approval template created', Response::HTTP_CREATED);
    }

    /**
     * Update an approval template.
     */
    #[OA\Put(
        path: '/api/v1/dms/approval-templates/{id}',
        operationId: 'updateDmsApprovalTemplate',
        description: 'Change a template. Sending `steps` replaces the route and is refused with 409 while a request is pending on the template. Owners and administrators only.',
        summary: 'Update an approval template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsSaveApprovalTemplateRequest')),
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Template updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalTemplateResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A request is pending on the template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveApprovalTemplateRequest $request, string $id): JsonResponse
    {
        $this->access->assertManager($request->user());

        return $this->successResponse(new ApprovalTemplateResource($this->approvals->updateTemplate(DmsApprovalTemplate::query()->findOrFail($id), $request->validated())), 'Approval template updated');
    }

    /**
     * Delete an approval template.
     */
    #[OA\Delete(
        path: '/api/v1/dms/approval-templates/{id}',
        operationId: 'deleteDmsApprovalTemplate',
        description: 'Delete a template that has no pending request. Finished requests keep the template name. Owners and administrators only.',
        summary: 'Delete an approval template',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Template deleted'),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'A request is pending on the template', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(string $id): Response
    {
        $this->access->assertManager(request()->user());
        $this->approvals->deleteTemplate(DmsApprovalTemplate::query()->findOrFail($id));

        return response()->noContent();
    }
}
