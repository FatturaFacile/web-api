<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\CreateEcoRequest;
use Modules\Manufacturing\Http\Requests\ReasonRequest;
use Modules\Manufacturing\Http\Resources\MfgEngineeringChangeOrderResource;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Services\EngineeringChangeService;
use OpenApi\Attributes as OA;

/**
 * Engineering change orders: create, submit, approve or reject (a manager other than the requester), implement as a new
 * BOM revision.
 */
final class EngineeringChangeController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly EngineeringChangeService $changes) {}

    /**
     * Paginated engineering changes.
     */
    #[OA\Get(path: '/api/v1/manufacturing/engineering-changes', operationId: 'listMfgEngineeringChanges', description: 'Paginated engineering changes of the active company, filtered by status.', summary: 'List engineering changes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Engineering changes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgEngineeringChange'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MfgEngineeringChangeOrder::query()->where('tenant_id', $this->tenantId());
        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        return MfgEngineeringChangeOrderResource::collection($query->latest()->gridPaginate($this->perPage($request)));
    }

    /**
     * Creates a draft engineering change.
     */
    #[OA\Post(path: '/api/v1/manufacturing/engineering-changes', operationId: 'storeMfgEngineeringChange', description: 'Creates a draft change on a released BOM revision with the component changes to apply and the date from which they are effective.', summary: 'Create engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgCreateEcoRequest')),
        responses: [new OA\Response(response: 201, description: 'Change created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function store(CreateEcoRequest $request): JsonResponse
    {
        /** @var array{bom_id: string, title: string, reason: string, description?: string|null, change_type?: string|null, effective_date?: string|null, new_revision?: string|null, components: list<array<string, mixed>>} $data */
        $data = $request->validated();

        return (new MfgEngineeringChangeOrderResource($this->changes->create($this->tenantId(), $data, $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * One engineering change.
     */
    #[OA\Get(path: '/api/v1/manufacturing/engineering-changes/{id}', operationId: 'showMfgEngineeringChange', description: 'Returns an engineering change of the active company.', summary: 'Get engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Engineering change', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404)])]
    public function show(string $id): MfgEngineeringChangeOrderResource
    {
        return new MfgEngineeringChangeOrderResource($this->owned(MfgEngineeringChangeOrder::class, $id));
    }

    /**
     * Submits a draft change for approval.
     */
    #[OA\Post(path: '/api/v1/manufacturing/engineering-changes/{id}/submit', operationId: 'submitMfgEngineeringChange', description: 'Submits a draft change for approval.', summary: 'Submit engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Change submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function submit(string $id): MfgEngineeringChangeOrderResource
    {
        $eco = $this->owned(MfgEngineeringChangeOrder::class, $id);
        $this->authorize('submit', $eco);

        return new MfgEngineeringChangeOrderResource($this->changes->submit($eco));
    }

    /**
     * Approves a submitted change.
     */
    #[OA\Post(path: '/api/v1/manufacturing/engineering-changes/{id}/approve', operationId: 'approveMfgEngineeringChange', description: 'Approves a submitted change: manufacturing managers only, never the requester.', summary: 'Approve engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Change approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function approve(Request $request, string $id): MfgEngineeringChangeOrderResource
    {
        $eco = $this->owned(MfgEngineeringChangeOrder::class, $id);
        $this->authorize('decide', $eco);

        return new MfgEngineeringChangeOrderResource($this->changes->approve($eco, (string) $this->actor($request)));
    }

    /**
     * Rejects a submitted change.
     */
    #[OA\Post(path: '/api/v1/manufacturing/engineering-changes/{id}/reject', operationId: 'rejectMfgEngineeringChange', description: 'Rejects a submitted change with the reason: manufacturing managers only, never the requester.', summary: 'Reject engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgReasonRequest')),
        responses: [new OA\Response(response: 200, description: 'Change rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function reject(ReasonRequest $request, string $id): MfgEngineeringChangeOrderResource
    {
        $eco = $this->owned(MfgEngineeringChangeOrder::class, $id);
        $this->authorize('decide', $eco);

        return new MfgEngineeringChangeOrderResource($this->changes->reject($eco, (string) $this->actor($request), (string) $request->validated('reason')));
    }

    /**
     * Implements an approved change.
     */
    #[OA\Post(path: '/api/v1/manufacturing/engineering-changes/{id}/implement', operationId: 'implementMfgEngineeringChange', description: 'Implements an approved change: a new BOM revision with the component changes, effective from the change date; the previous revision ends the day before.', summary: 'Implement engineering change', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Engineering Changes'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        responses: [new OA\Response(response: 200, description: 'Change implemented', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgEngineeringChange')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function implement(Request $request, string $id): MfgEngineeringChangeOrderResource
    {
        $eco = $this->owned(MfgEngineeringChangeOrder::class, $id);
        $this->authorize('implement', $eco);

        return new MfgEngineeringChangeOrderResource($this->changes->implement($eco, $this->actor($request)));
    }
}
