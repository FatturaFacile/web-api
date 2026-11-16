<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\ControlRequest;
use Modules\Governance\Http\Requests\ControlTestRequest;
use Modules\Governance\Http\Resources\ControlResource;
use Modules\Governance\Http\Resources\ControlTestResource;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovControlTestRun;
use Modules\Governance\Services\ControlService;
use OpenApi\Attributes as OA;

/**
 * The controls library and the tests of the controls.
 */
final class ControlController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly ControlService $controls) {}

    #[OA\Get(path: '/api/v1/governance/controls', operationId: 'listGovControls', description: 'Paginated controls by code. Filters: status, control_type, frequency, owner_id, test_overdue=1 (active controls past their next test date), risk_id (controls linked to a risk), search (code or title).', summary: 'List controls', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['draft', 'active', 'retired'])),
        new OA\Parameter(name: 'control_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['preventive', 'detective', 'corrective'])),
        new OA\Parameter(name: 'frequency', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual', 'ad_hoc'])),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'risk_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'test_overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Controls', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovControl'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovControl::class);

        return ControlResource::collection(
            GovControl::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('control_type'), static fn ($query) => $query->where('control_type', (string) $request->query('control_type')))
                ->when($request->filled('frequency'), static fn ($query) => $query->where('frequency', (string) $request->query('frequency')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->filled('risk_id'), static fn ($query) => $query->whereHas('risks', static fn ($risks) => $risks->where('gov_risks.id', (string) $request->query('risk_id'))))
                ->when($request->boolean('test_overdue'), static fn ($query) => $query->where('status', 'active')->whereDate('next_test_due_date', '<', now()->toDateString()))
                ->when($request->filled('search'), static fn ($query) => $query->where(static fn ($search) => $search->where('title', 'like', '%'.$request->query('search').'%')->orWhere('code', 'like', '%'.$request->query('search').'%')))
                ->orderBy('code')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/controls', operationId: 'storeGovControl', description: 'Adds a control to the library as a draft, or active when status is active (the first test is then scheduled from the frequency).', summary: 'Add a control', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovControlRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Control added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovControl')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(ControlRequest $request): JsonResponse
    {
        $this->authorize('create', GovControl::class);

        return (new ControlResource($this->controls->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/controls/{id}', operationId: 'showGovControl', description: 'A control with the ids of the risks it mitigates.', summary: 'Show a control', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Control', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovControl')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): ControlResource
    {
        $control = $this->find($id);
        $this->authorize('view', $control);

        return new ControlResource($control->load('risks'));
    }

    #[OA\Put(path: '/api/v1/governance/controls/{id}', operationId: 'updateGovControl', description: 'Changes a control. A change of status follows draft, active and retired: activating schedules the first test.', summary: 'Update a control', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovControlRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Control updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovControl')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(ControlRequest $request, string $id): ControlResource
    {
        $control = $this->find($id);
        $this->authorize('update', $control);

        return new ControlResource($this->controls->update($control, $request->validated()));
    }

    #[OA\Get(path: '/api/v1/governance/controls/{id}/tests', operationId: 'listGovControlTests', description: 'The tests of a control, most recent first.', summary: 'List the tests of a control', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Control tests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovControlTest'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function tests(string $id): AnonymousResourceCollection
    {
        $control = $this->find($id);
        $this->authorize('view', $control);

        return ControlTestResource::collection(GovControlTestRun::query()->where('control_id', $control->id)->orderByDesc('tested_on')->orderByDesc('created_at')->limit(200)->get());
    }

    #[OA\Post(path: '/api/v1/governance/controls/{id}/tests', operationId: 'recordGovControlTest', description: 'Records the test of an active control with its result (pass, partial, fail), sample and the DMS documents used as evidence. The next test is scheduled from the frequency (a failed control is retested within 30 days). With raise_finding a partial or failed result also opens a finding.', summary: 'Record a control test', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovControlTestRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 201, description: 'Test recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovControlTest')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function recordTest(ControlTestRequest $request, string $id): JsonResponse
    {
        $control = $this->find($id);
        $this->authorize('progress', $control);

        return (new ControlTestResource($this->controls->recordTest($control, $request->validated(), $this->userId())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function find(string $id): GovControl
    {
        return GovControl::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
