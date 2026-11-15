<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Documents\Enums\ApprovalStatus;
use Modules\Documents\Http\Requests\DecideApprovalRequest;
use Modules\Documents\Http\Requests\RequestApprovalRequest;
use Modules\Documents\Http\Resources\ApprovalRequestResource;
use Modules\Documents\Models\DmsApprovalRequest;
use Modules\Documents\Models\DmsApprovalTemplate;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\DocumentApprovalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approval requests of documents: start one, follow it and decide the step that is waiting.
 */
final class ApprovalRequestController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access, private readonly DocumentApprovalService $approvals) {}

    /**
     * List approval requests.
     */
    #[OA\Get(
        path: '/api/v1/dms/approval-requests',
        operationId: 'listDmsApprovalRequests',
        description: 'Approval requests, newest first. Filters: `status` (pending, approved, rejected, cancelled), `document_id`, and `awaiting_me=1` for the pending requests whose current step the caller decides. Requests on documents the role cannot see are left out.',
        summary: 'List approval requests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Approvals'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'cancelled'])),
            new OA\Parameter(name: 'document_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'awaiting_me', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsApprovalRequestResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(ApprovalStatus::class)],
            'document_id' => ['nullable', 'uuid'],
            'awaiting_me' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $role = $this->access->roleOf($user);
        $visibleDocuments = $this->access->restrict(DmsDocument::query(), $user)->select('dms_documents.id');

        $requests = DmsApprovalRequest::query()
            ->with(['document:id,title', 'template.steps'])
            ->whereIn('document_id', $visibleDocuments)
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['document_id']), static fn ($query) => $query->where('document_id', $filters['document_id']))
            ->when($request->boolean('awaiting_me'), function ($query) use ($user, $role): void {
                $query->where('status', ApprovalStatus::Pending->value)
                    ->where(function ($pending) use ($user): void {
                        $pending->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id);
                    })
                    ->whereExists(function (QueryBuilder $step) use ($user, $role): void {
                        $step->selectRaw('1')
                            ->from('dms_approval_template_steps as s')
                            ->whereColumn('s.template_id', 'dms_approval_requests.template_id')
                            ->whereColumn('s.position', 'dms_approval_requests.current_step')
                            ->whereNull('s.deleted_at')
                            ->where(function ($who) use ($user, $role): void {
                                $who->where(fn ($byUser) => $byUser->where('s.approver_type', 'user')->where('s.approver_user_id', $user->id))
                                    ->orWhere(fn ($byRole) => $byRole->where('s.approver_type', 'role')->where('s.approver_role', $role));
                            });
                    });
            })
            ->latest()
            ->paginate(min(100, max(5, (int) $request->query('per_page', 25))));

        return $this->successResponse(
            ApprovalRequestResource::collection($requests->getCollection()),
            meta: ['current_page' => $requests->currentPage(), 'last_page' => $requests->lastPage(), 'per_page' => $requests->perPage(), 'total' => $requests->total()],
        );
    }

    /**
     * Show an approval request.
     */
    #[OA\Get(
        path: '/api/v1/dms/approval-requests/{id}',
        operationId: 'showDmsApprovalRequest',
        description: 'One request with the current step and the decisions taken so far.',
        summary: 'Show an approval request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->successResponse(new ApprovalRequestResource($this->visibleRequest($request, $id)));
    }

    /**
     * Start an approval for a document.
     */
    #[OA\Post(
        path: '/api/v1/dms/documents/{id}/approval-requests',
        operationId: 'createDmsApprovalRequest',
        description: 'Send the current version of the document through an active template. A document has at most one pending request.',
        summary: 'Request approval of a document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsRequestApprovalRequest')),
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'Request created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Document not found or not visible to this role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Template inactive or without steps, or a request is already pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(RequestApprovalRequest $request, string $id): JsonResponse
    {
        $document = $this->access->findVisible($id, $request->user());
        $template = DmsApprovalTemplate::query()->findOrFail($request->validated('template_id'));
        $approval = $this->approvals->request($document, $template, $request->user(), $request->validated('note'));

        return $this->successResponse(new ApprovalRequestResource($approval->load(['document:id,title', 'template.steps', 'decisions'])), 'Approval requested', Response::HTTP_CREATED);
    }

    /**
     * Approve the current step.
     */
    #[OA\Post(
        path: '/api/v1/dms/approval-requests/{id}/approve',
        operationId: 'approveDmsApprovalRequest',
        description: 'Approve the current step. Only its approver can, never the requester. The last approval completes the request.',
        summary: 'Approve a step',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/DmsDecideApprovalRequest')),
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Decision recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not the approver of the current step', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The request is no longer pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function approve(DecideApprovalRequest $request, string $id): JsonResponse
    {
        $approval = $this->approvals->decide($this->visibleRequest($request, $id), $request->user(), true, $request->validated('comment'));

        return $this->successResponse(new ApprovalRequestResource($approval->load(['document:id,title', 'template.steps', 'decisions'])), 'Step approved');
    }

    /**
     * Reject the request.
     */
    #[OA\Post(
        path: '/api/v1/dms/approval-requests/{id}/reject',
        operationId: 'rejectDmsApprovalRequest',
        description: 'Reject at the current step, with the reason. A rejection ends the request.',
        summary: 'Reject an approval request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/DmsDecideApprovalRequest')),
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Request rejected', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not the approver of the current step', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The request is no longer pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'The reason is missing', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function reject(DecideApprovalRequest $request, string $id): JsonResponse
    {
        $approval = $this->approvals->decide($this->visibleRequest($request, $id), $request->user(), false, $request->validated('comment'));

        return $this->successResponse(new ApprovalRequestResource($approval->load(['document:id,title', 'template.steps', 'decisions'])), 'Approval request rejected');
    }

    /**
     * Cancel a pending request.
     */
    #[OA\Post(
        path: '/api/v1/dms/approval-requests/{id}/cancel',
        operationId: 'cancelDmsApprovalRequest',
        description: 'Cancel a pending request. The requester can, and so can owners and administrators.',
        summary: 'Cancel an approval request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Approvals'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Request cancelled', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/DmsApprovalRequestResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Neither the requester nor a manager', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The request is no longer pending', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function cancel(Request $request, string $id): JsonResponse
    {
        $approval = $this->approvals->cancel($this->visibleRequest($request, $id), $request->user());

        return $this->successResponse(new ApprovalRequestResource($approval->load(['document:id,title', 'template.steps', 'decisions'])), 'Approval request cancelled');
    }

    private function visibleRequest(Request $request, string $id): DmsApprovalRequest
    {
        $approval = DmsApprovalRequest::query()->with(['document:id,title', 'template.steps', 'decisions'])->findOrFail($id);
        $this->access->findVisible((string) $approval->document_id, $request->user());

        return $approval;
    }
}
