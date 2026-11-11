<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Modules\Quality\Http\Controllers\Concerns\InteractsWithQuality;
use Modules\Quality\Http\Resources\CapaResource;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Services\CapaService;
use OpenApi\Attributes as OA;

/**
 * Corrective and preventive actions (CAPA) with owner, due date and effectiveness check.
 */
final class CapaController extends Controller
{
    use InteractsWithQuality;

    public function __construct(private readonly CapaService $capa) {}

    #[OA\Get(path: '/api/v1/quality/capa-actions', operationId: 'listQmsCapaActions', description: 'Paginated corrective and preventive actions, by due date. Filters: status, nonconformity_id, owner_id, overdue=1 (open or in progress past their due date).', summary: 'List CAPA actions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'in_progress', 'completed', 'verified', 'ineffective', 'cancelled'])),
        new OA\Parameter(name: 'nonconformity_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'CAPA actions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/QmsCapaAction'))])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QmsCapaAction::class);

        return CapaResource::collection(
            QmsCapaAction::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('nonconformity_id'), static fn ($query) => $query->where('nonconformity_id', (string) $request->query('nonconformity_id')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', QmsCapaAction::OpenStatuses)->whereDate('due_date', '<', now()->toDateString()))
                ->orderBy('due_date')
                ->orderBy('number')
                ->paginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/quality/capa-actions', operationId: 'storeQmsCapaAction', description: 'Raises a corrective or preventive action with its owner and due date, tied to an open non-conformity or standalone (preventive). The first action of a non-conformity moves it to capa_in_progress.', summary: 'Raise a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCapaRequest')), tags: ['Quality'], responses: [
        new OA\Response(response: 201, description: 'Action raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', QmsCapaAction::class);
        $validated = $request->validate([
            'nonconformity_id' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::in(QmsCapaAction::Types)],
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:4000'],
            'owner_id' => ['required', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'due_date' => ['required', 'date'],
            'effectiveness_check_due_date' => ['nullable', 'date', 'after_or_equal:due_date'],
        ]);

        return (new CapaResource($this->capa->create($this->tenantId(), $validated)))->response()->setStatusCode(201);
    }

    #[OA\Get(path: '/api/v1/quality/capa-actions/{id}', operationId: 'showQmsCapaAction', description: 'A corrective or preventive action.', summary: 'Show a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Action', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
    ])]
    public function show(string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('view', $action);

        return new CapaResource($action);
    }

    #[OA\Put(path: '/api/v1/quality/capa-actions/{id}', operationId: 'updateQmsCapaAction', description: 'Changes title, description, owner, due date or effectiveness check date of an action that is open or in progress.', summary: 'Update a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCapaUpdateRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Action updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function update(Request $request, string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:190'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'owner_id' => ['sometimes', 'uuid', Rule::exists('tenant_user', 'user_id')->where('tenant_id', $this->tenantId())],
            'due_date' => ['sometimes', 'date'],
            'effectiveness_check_due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        return new CapaResource($this->capa->update($action, $validated));
    }

    #[OA\Post(path: '/api/v1/quality/capa-actions/{id}/start', operationId: 'startQmsCapaAction', description: 'Marks an open action as in progress.', summary: 'Start a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Action in progress', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function start(string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);

        return new CapaResource($this->capa->start($action));
    }

    #[OA\Post(path: '/api/v1/quality/capa-actions/{id}/complete', operationId: 'completeQmsCapaAction', description: 'Records that the action was carried out. It is not finished until its effectiveness is verified.', summary: 'Complete a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/QmsCapaCompleteRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Action completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function complete(Request $request, string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);
        $validated = $request->validate(['effectiveness_check_due_date' => ['nullable', 'date']]);

        return new CapaResource($this->capa->complete($action, $this->userId(), $validated['effectiveness_check_due_date'] ?? null));
    }

    #[OA\Post(path: '/api/v1/quality/capa-actions/{id}/verify-effectiveness', operationId: 'verifyQmsCapaEffectiveness', description: 'Records the effectiveness check of a completed action: effective makes it verified, not_effective makes it ineffective (it blocks the closure of the non-conformity until a follow-up action is verified or this one is cancelled).', summary: 'Verify the effectiveness of a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/QmsCapaVerifyRequest')), tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Effectiveness recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function verifyEffectiveness(Request $request, string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);
        $validated = $request->validate([
            'result' => ['required', Rule::in(['effective', 'not_effective'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return new CapaResource($this->capa->verifyEffectiveness($action, $validated['result'], $validated['notes'] ?? null, $this->userId()));
    }

    #[OA\Post(path: '/api/v1/quality/capa-actions/{id}/cancel', operationId: 'cancelQmsCapaAction', description: 'Cancels an action that is no longer needed.', summary: 'Cancel a CAPA action', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Quality'], parameters: [new OA\Parameter(ref: '#/components/parameters/QualityId')], responses: [
        new OA\Response(response: 200, description: 'Action cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/QmsCapaAction')])),
        new OA\Response(ref: '#/components/responses/QualityUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/QualityForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/QualityNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/QualityUnprocessable', response: 422),
    ])]
    public function cancel(string $id): CapaResource
    {
        $action = $this->find($id);
        $this->authorize('update', $action);

        return new CapaResource($this->capa->cancel($action));
    }

    private function find(string $id): QmsCapaAction
    {
        return QmsCapaAction::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
