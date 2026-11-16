<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Resources\AuditResource;
use Modules\Quality\Http\Resources\ChecklistItemResource;
use Modules\Quality\Http\Resources\FindingResource;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsAuditChecklistItem;
use Modules\Quality\Models\QmsAuditFinding;
use Modules\Quality\Services\AuditService;
use OpenApi\Attributes as OA;

/**
 * Internal audits with checklists and findings.
 */
final class AuditController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly AuditService $audits) {}

    #[OA\Get(path: '/api/v1/quality/audits', operationId: 'listQmsAudits', description: 'Paginated audits by planned date, newest first. Filters: status, audit_type.', summary: 'List audits', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['planned', 'in_progress', 'completed', 'cancelled'])),
        new OA\Parameter(name: 'audit_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['internal', 'process', 'supplier'])),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Audits', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsAudit'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsAudit::class);

        return AuditResource::collection(
            QmsAudit::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('audit_type'), static fn ($query) => $query->where('audit_type', (string) $request->query('audit_type')))
                ->orderByDesc('planned_date')
                ->orderByDesc('number')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/audits', operationId: 'storeQmsAudit', description: 'Plans an audit with its checklist of requirements (clause and requirement text); every item starts as pending.', summary: 'Plan an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsAuditRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Audit planned', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QmsAudit::class);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'scope' => ['nullable', 'string', 'max:4000'],
            'audit_type' => ['nullable', Rule::in(QmsAudit::Types)],
            'planned_date' => ['required', 'date'],
            'lead_auditor_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'auditee' => ['nullable', 'string', 'max:190'],
            'checklist' => ['nullable', 'array', 'max:500'],
            'checklist.*.clause' => ['nullable', 'string', 'max:60'],
            'checklist.*.requirement' => ['required', 'string', 'max:2000'],
        ]);

        $audit = $this->audits->create($this->tenantId(), $validated)->load(['checklistItems', 'findings']);

        return (new AuditResource($audit))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/audits/{id}', operationId: 'showQmsAudit', description: 'An audit with its checklist and findings.', summary: 'Show an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Audit', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): AuditResource
    {
        $audit = $this->find($id)->load(['checklistItems', 'findings']);
        $this->authorize('view', $audit);

        return new AuditResource($audit);
    }

    #[OA\Put(path: '/api/v1/quality/audits/{id}', operationId: 'updateQmsAudit', description: 'Changes title, scope, type, planned date, lead auditor or auditee of a planned audit.', summary: 'Update an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsAuditUpdateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Audit updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(Request $request, string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'scope' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'audit_type' => ['sometimes', Rule::in(QmsAudit::Types)],
            'planned_date' => ['sometimes', 'date'],
            'lead_auditor_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'auditee' => ['sometimes', 'nullable', 'string', 'max:190'],
        ]);

        return new AuditResource($this->audits->update($audit, $validated));
    }

    #[OA\Post(path: '/api/v1/quality/audits/{id}/start', operationId: 'startQmsAudit', description: 'Starts a planned audit; the performed date is today.', summary: 'Start an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Audit in progress', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function start(string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);

        return new AuditResource($this->audits->start($audit));
    }

    #[OA\Post(path: '/api/v1/quality/audits/{id}/complete', operationId: 'completeQmsAudit', description: 'Completes an audit in progress, with the summary. Every checklist item must have a result.', summary: 'Complete an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsAuditCompleteRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Audit completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function complete(Request $request, string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);
        $validated = $request->validate(['summary' => ['nullable', 'string', 'max:4000']]);

        return new AuditResource($this->audits->complete($audit, $validated['summary'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/audits/{id}/cancel', operationId: 'cancelQmsAudit', description: 'Cancels a planned or in-progress audit.', summary: 'Cancel an audit', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Audit cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAudit')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): AuditResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);

        return new AuditResource($this->audits->cancel($audit));
    }

    #[OA\Put(path: '/api/v1/quality/audits/{id}/checklist/{itemId}', operationId: 'recordQmsAuditChecklistResult', description: 'Records the result of a checklist item while the audit is in progress.', summary: 'Record a checklist result', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsChecklistResultRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId'), new OA\Parameter(ref: '#/components/parameters/QualityItemId')], responses: [
        new OA\Response(response: 200, description: 'Result recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAuditChecklistItem')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function recordResult(Request $request, string $id, string $itemId): ChecklistItemResource
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);
        $item = QmsAuditChecklistItem::query()->where('tenant_id', $this->tenantId())->where('audit_id', $audit->id)->findOrFail($itemId);
        $validated = $request->validate([
            'result' => ['required', Rule::in(QmsAuditChecklistItem::Results)],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return new ChecklistItemResource($this->audits->recordResult($audit, $item, $validated['result'], $validated['notes'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/audits/{id}/findings', operationId: 'storeQmsAuditFinding', description: 'Raises a finding while the audit is in progress. A major or minor non-conformity finding raises a non-conformity of source audit, linked to it, and marks its checklist item as non-conforming.', summary: 'Raise an audit finding', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsFindingRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 201, description: 'Finding raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsAuditFinding')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function storeFinding(Request $request, string $id): JsonResponse
    {
        $audit = $this->find($id);
        $this->authorize('update', $audit);
        $validated = $request->validate([
            'classification' => ['required', Rule::in(QmsAuditFinding::Classifications)],
            'description' => ['required', 'string', 'max:4000'],
            'checklist_item_id' => ['nullable', 'uuid'],
            'owner_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
        ]);

        return (new FindingResource($this->audits->addFinding($audit, $validated, $this->userId())))->response()->setStatusCode(201);
    }

    private function find(string $id): QmsAudit
    {
        return QmsAudit::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
