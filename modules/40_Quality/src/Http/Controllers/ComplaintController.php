<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Requests\ComplaintActionRequest;
use Modules\Quality\Http\Requests\ComplaintRequest;
use Modules\Quality\Http\Resources\ComplaintResource;
use Modules\Quality\Models\QmsComplaint;
use Modules\Quality\Services\ComplaintService;
use OpenApi\Attributes as OA;

/**
 * Customer complaints: intake, investigation (root cause, non-conformity, CAPA), customer reply and closure.
 */
final class ComplaintController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly ComplaintService $complaints) {}

    #[OA\Get(path: '/api/v1/quality/complaints', operationId: 'listQmsComplaints', description: 'Paginated customer complaints, newest first. Filters: status, severity, owner_id, open=1 (received, investigating or replied).', summary: 'List customer complaints', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['received', 'investigating', 'replied', 'closed', 'rejected'])),
        new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['minor', 'major', 'critical'])),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Complaints', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsComplaint'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsComplaint::class);

        return ComplaintResource::collection(
            QmsComplaint::query()->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('severity'), static fn ($query) => $query->where('severity', (string) $request->query('severity')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('open'), static fn ($query) => $query->whereIn('status', QmsComplaint::OpenStatuses))
                ->orderByDesc('received_at')->orderByDesc('number')->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/complaints', operationId: 'storeQmsComplaint', description: 'Registers a customer complaint (numbered CMP-YYYY-NNNN) in status received.', summary: 'Register a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Complaint registered', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(ComplaintRequest $request): JsonResponse
    {
        $this->authorize('create', QmsComplaint::class);

        return (new ComplaintResource($this->complaints->create($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/complaints/{id}', operationId: 'showQmsComplaint', description: 'A complaint.', summary: 'Show a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Complaint', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('view', $complaint);

        return new ComplaintResource($complaint);
    }

    #[OA\Put(path: '/api/v1/quality/complaints/{id}', operationId: 'updateQmsComplaint', description: 'Changes a complaint that is not yet closed or rejected.', summary: 'Update a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Complaint updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(ComplaintRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->update($complaint, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/investigate', operationId: 'investigateQmsComplaint', description: 'Starts the investigation of a received complaint, optionally assigning an owner.', summary: 'Start investigating a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintInvestigateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Complaint investigating', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function investigate(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->investigate($complaint, $request->validated()['owner_id'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/root-cause', operationId: 'rootCauseQmsComplaint', description: 'Records the root cause found by the investigation.', summary: 'Record the root cause of a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintRootCauseRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Root cause recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function rootCause(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->recordRootCause($complaint, $request->validated()['root_cause']));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/raise-nonconformity', operationId: 'raiseNonconformityQmsComplaint', description: 'Opens a non-conformity with source customer_complaint from the complaint (same severity, customer and description) and links it.', summary: 'Raise a non-conformity from a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Non-conformity linked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function raiseNonconformity(string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->raiseNonconformity($complaint, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/link', operationId: 'linkQmsComplaint', description: 'Links an existing non-conformity and/or CAPA action of the company to the complaint. A CAPA action tied to another non-conformity is refused.', summary: 'Link a non-conformity or CAPA to a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintLinkRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Linked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function link(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);
        $validated = $request->validated();

        return new ComplaintResource($this->complaints->link($complaint, $validated['nonconformity_id'] ?? null, $validated['capa_action_id'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/reply', operationId: 'replyQmsComplaint', description: 'Records the reply sent to the customer; the complaint becomes replied.', summary: 'Reply to the customer', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintReplyRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Reply recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function reply(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->reply($complaint, $request->validated()['reply_text'], $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/reject', operationId: 'rejectQmsComplaint', description: 'Rejects a complaint that is not justified, with the reason.', summary: 'Reject a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsComplaintRejectRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Complaint rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function reject(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->reject($complaint, $request->validated()['reason'], $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/complaints/{id}/close', operationId: 'closeQmsComplaint', description: 'Closes a replied complaint. It needs a root cause, and the linked non-conformity (if any) must be closed or cancelled.', summary: 'Close a complaint', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsCloseRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Complaint closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsComplaint')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function close(ComplaintActionRequest $request, string $id): ComplaintResource
    {
        $complaint = $this->find($id);
        $this->authorize('update', $complaint);

        return new ComplaintResource($this->complaints->close($complaint, $request->validated()['closure_notes'] ?? null, $this->userId()));
    }

    private function find(string $id): QmsComplaint
    {
        return QmsComplaint::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
