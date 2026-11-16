<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Resources\NonconformityResource;
use Modules\Quality\Models\QmsNonconformity;
use Modules\Quality\Services\NonconformityService;
use OpenApi\Attributes as OA;

/**
 * Non-conformities of the quality management system, from any source (supplier, customer complaint, internal, audit,
 * manufacturing).
 */
final class NonconformityController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly NonconformityService $nonconformities) {}

    #[OA\Get(path: '/api/v1/quality/nonconformities', operationId: 'listQmsNonconformities', description: 'Paginated non-conformities of the company, newest first. Filters: status, source, severity, open=1 (open, investigating or CAPA in progress), owner_id.', summary: 'List non-conformities', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'investigating', 'capa_in_progress', 'closed', 'cancelled'])),
        new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['supplier', 'customer_complaint', 'internal', 'audit', 'manufacturing'])),
        new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['minor', 'major', 'critical'])),
        new OA\Parameter(name: 'open', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Non-conformities', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsNonconformity'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsNonconformity::class);

        return NonconformityResource::collection(
            QmsNonconformity::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('source'), static fn ($query) => $query->where('source', (string) $request->query('source')))
                ->when($request->filled('severity'), static fn ($query) => $query->where('severity', (string) $request->query('severity')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('open'), static fn ($query) => $query->whereIn('status', QmsNonconformity::OpenStatuses))
                ->orderByDesc('detected_at')
                ->orderByDesc('number')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/nonconformities', operationId: 'storeQmsNonconformity', description: 'Raises a non-conformity. mfg_non_conformance_id (a manufacturing non-conformance of the company, referenced by id and never changed) is accepted only for the source manufacturing. The number is progressive per company and year (NC-2027-0001).', summary: 'Raise a non-conformity', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsNonconformityRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Non-conformity raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QmsNonconformity::class);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'source' => ['required', Rule::in(QmsNonconformity::Sources)],
            'severity' => ['nullable', Rule::in(QmsNonconformity::Severities)],
            'detected_at' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $this->tenantId())],
            'mfg_non_conformance_id' => ['nullable', 'uuid'],
            'containment_action' => ['nullable', 'string', 'max:4000'],
        ]);

        $nonconformity = $this->nonconformities->create($this->tenantId(), $validated, $this->userId());

        return (new NonconformityResource($nonconformity))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/nonconformities/{id}', operationId: 'showQmsNonconformity', description: 'A non-conformity with its corrective and preventive actions.', summary: 'Show a non-conformity', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Non-conformity', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): NonconformityResource
    {
        $nonconformity = $this->find($id)->load('capaActions');
        $this->authorize('view', $nonconformity);

        return new NonconformityResource($nonconformity);
    }

    #[OA\Put(path: '/api/v1/quality/nonconformities/{id}', operationId: 'updateQmsNonconformity', description: 'Changes the description, severity, owner, contact, containment and root cause of an open non-conformity. Source and number never change.', summary: 'Update a non-conformity', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsNonconformityUpdateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Non-conformity updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(Request $request, string $id): NonconformityResource
    {
        $nonconformity = $this->find($id);
        $this->authorize('update', $nonconformity);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'severity' => ['sometimes', Rule::in(QmsNonconformity::Severities)],
            'owner_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'contact_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $this->tenantId())],
            'containment_action' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'root_cause' => ['sometimes', 'nullable', 'string', 'max:4000'],
        ]);

        return new NonconformityResource($this->nonconformities->update($nonconformity, $validated));
    }

    #[OA\Post(path: '/api/v1/quality/nonconformities/{id}/investigate', operationId: 'investigateQmsNonconformity', description: 'Starts the investigation (open to investigating), optionally assigning the owner.', summary: 'Start the investigation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsInvestigateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Investigation started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function investigate(Request $request, string $id): NonconformityResource
    {
        $nonconformity = $this->find($id);
        $this->authorize('update', $nonconformity);
        $validated = $request->validate(['owner_id' => ['nullable', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())]]);

        return new NonconformityResource($this->nonconformities->startInvestigation($nonconformity, $validated['owner_id'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/nonconformities/{id}/close', operationId: 'closeQmsNonconformity', description: 'Closes a non-conformity. Every action must be verified or cancelled; a major or critical non-conformity also needs a root cause and one verified corrective action. The audit findings that raised it are closed with it.', summary: 'Close a non-conformity', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsCloseRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Non-conformity closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function close(Request $request, string $id): NonconformityResource
    {
        $nonconformity = $this->find($id);
        $this->authorize('update', $nonconformity);
        $validated = $request->validate(['closure_notes' => ['nullable', 'string', 'max:4000']]);

        return new NonconformityResource($this->nonconformities->close($nonconformity, $validated['closure_notes'] ?? null, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/nonconformities/{id}/cancel', operationId: 'cancelQmsNonconformity', description: 'Cancels an open non-conformity raised by mistake, with the reason.', summary: 'Cancel a non-conformity', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCancelRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Non-conformity cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsNonconformity')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function cancel(Request $request, string $id): NonconformityResource
    {
        $nonconformity = $this->find($id);
        $this->authorize('update', $nonconformity);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return new NonconformityResource($this->nonconformities->cancel($nonconformity, $validated['reason']));
    }

    private function find(string $id): QmsNonconformity
    {
        return QmsNonconformity::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
