<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Governance\Http\Controllers\Concerns\InteractsWithGovernance;
use Modules\Governance\Http\Requests\ObligationCompleteRequest;
use Modules\Governance\Http\Requests\ObligationRequest;
use Modules\Governance\Http\Requests\SeedObligationTemplatesRequest;
use Modules\Governance\Http\Requests\TransitionRequest;
use Modules\Governance\Http\Resources\ObligationResource;
use Modules\Governance\Models\GovObligation;
use Modules\Governance\Services\ComplianceTemplates;
use Modules\Governance\Services\ObligationService;
use OpenApi\Attributes as OA;

/**
 * The compliance obligations calendar (D.Lgs. 231/2001, GDPR, NIS2, Swiss FADP, ISO 27001) with seeded templates.
 */
final class ObligationController extends Controller
{
    use InteractsWithGovernance;

    public function __construct(private readonly ObligationService $obligations) {}

    #[OA\Get(path: '/api/v1/governance/obligations', operationId: 'listGovObligations', description: 'The calendar by due date. Filters: framework, jurisdiction, status, owner_id, overdue=1 (open obligations past their due date), due_within_days (open obligations due in the next N days), search (title).', summary: 'List compliance obligations', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [
        new OA\Parameter(name: 'framework', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['d_lgs_231', 'gdpr', 'nis2', 'fadp', 'iso_27001', 'other'])),
        new OA\Parameter(name: 'jurisdiction', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['IT', 'CH', 'EU', 'INT'])),
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled'])),
        new OA\Parameter(name: 'owner_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        new OA\Parameter(name: 'overdue', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        new OA\Parameter(name: 'due_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 0, maximum: 730)),
        new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 100)),
    ], responses: [
        new OA\Response(response: 200, description: 'Obligations', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovObligation'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GovObligation::class);

        return ObligationResource::collection(
            GovObligation::query()
                ->where('tenant_id', $this->tenantId())
                ->when($request->filled('framework'), static fn ($query) => $query->where('framework', (string) $request->query('framework')))
                ->when($request->filled('jurisdiction'), static fn ($query) => $query->where('jurisdiction', (string) $request->query('jurisdiction')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
                ->when($request->filled('owner_id'), static fn ($query) => $query->where('owner_id', (string) $request->query('owner_id')))
                ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', GovObligation::OpenStatuses)->whereDate('due_date', '<', now()->toDateString()))
                ->when($request->filled('due_within_days'), static fn ($query) => $query->whereIn('status', GovObligation::OpenStatuses)->whereDate('due_date', '>=', now()->toDateString())->whereDate('due_date', '<=', now()->addDays(max(0, min(730, (int) $request->query('due_within_days'))))->toDateString()))
                ->when($request->filled('search'), static fn ($query) => $query->where('title', 'like', '%'.$request->query('search').'%'))
                ->orderBy('due_date')
                ->orderBy('title')
                ->gridPaginate($this->perPage())
        );
    }

    #[OA\Post(path: '/api/v1/governance/obligations', operationId: 'storeGovObligation', description: 'Adds a compliance obligation to the calendar.', summary: 'Add a compliance obligation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovObligationRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 201, description: 'Obligation added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovObligation')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function store(ObligationRequest $request): JsonResponse
    {
        $this->authorize('create', GovObligation::class);

        return (new ObligationResource($this->obligations->create($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/governance/obligations/templates', operationId: 'listGovObligationTemplates', description: 'The seeded templates of the compliance calendar by framework (D.Lgs. 231/2001, GDPR, NIS2, Swiss FADP, ISO 27001), each flagged when the company already has it.', summary: 'List obligation templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/GovObligationTemplate'))])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
    ])]
    public function templates(): JsonResponse
    {
        $this->authorize('viewAny', GovObligation::class);
        $existing = GovObligation::query()->whereNotNull('template_key')->pluck('template_key')->all();

        return response()->json(['data' => array_map(
            static fn (array $template): array => $template + ['already_seeded' => in_array($template['key'], $existing, true)],
            ComplianceTemplates::all(),
        )]);
    }

    #[OA\Post(path: '/api/v1/governance/obligations/seed-templates', operationId: 'seedGovObligationTemplates', description: 'Creates the obligations of the templates of the chosen frameworks that the company does not have yet, all starting at first_due_date (default in 30 days). Running it again creates nothing twice.', summary: 'Seed obligations from templates', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovSeedObligationTemplatesRequest')), tags: ['Governance'], responses: [
        new OA\Response(response: 200, description: 'Templates seeded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', properties: [new OA\Property(property: 'created', type: 'integer'), new OA\Property(property: 'skipped', type: 'integer')], type: 'object')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function seedTemplates(SeedObligationTemplatesRequest $request): JsonResponse
    {
        $this->authorize('create', GovObligation::class);
        $validated = $request->validated();

        return response()->json(['data' => $this->obligations->seedTemplates($this->tenantId(), array_values($validated['frameworks']), $validated['first_due_date'] ?? null, $validated['owner_id'] ?? null)]);
    }

    #[OA\Get(path: '/api/v1/governance/obligations/{id}', operationId: 'showGovObligation', description: 'A compliance obligation.', summary: 'Show a compliance obligation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Obligation', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovObligation')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
    ])]
    public function show(string $id): ObligationResource
    {
        $obligation = $this->find($id);
        $this->authorize('view', $obligation);

        return new ObligationResource($obligation);
    }

    #[OA\Put(path: '/api/v1/governance/obligations/{id}', operationId: 'updateGovObligation', description: 'Changes an obligation that is pending or in progress.', summary: 'Update a compliance obligation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovObligationRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Obligation updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovObligation')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function update(ObligationRequest $request, string $id): ObligationResource
    {
        $obligation = $this->find($id);
        $this->authorize('update', $obligation);

        return new ObligationResource($this->obligations->update($obligation, $request->validated()));
    }

    #[OA\Post(path: '/api/v1/governance/obligations/{id}/transition', operationId: 'transitionGovObligation', description: 'Marks an obligation in progress, back to pending, or cancels it; a cancelled obligation can be reactivated as pending. Completion has its own action.', summary: 'Change the status of a compliance obligation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/GovTransitionRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Obligation updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovObligation')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function transition(TransitionRequest $request, string $id): ObligationResource
    {
        $obligation = $this->find($id);
        $this->authorize('update', $obligation);

        return new ObligationResource($this->obligations->transition($obligation, (string) $request->validated('status'), $this->userId()));
    }

    #[OA\Post(path: '/api/v1/governance/obligations/{id}/complete', operationId: 'completeGovObligation', description: 'Records the fulfilment with the date and the evidence document of the DMS. A one-off obligation becomes completed; a recurring one moves to its next due date and goes back to pending.', summary: 'Complete a compliance obligation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/GovObligationCompleteRequest')), tags: ['Governance'], parameters: [new OA\Parameter(ref: '#/components/parameters/GovernanceId')], responses: [
        new OA\Response(response: 200, description: 'Obligation completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/GovObligation')])),
        new OA\Response(ref: '#/components/responses/GovernanceUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/GovernanceForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/GovernanceNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/GovernanceUnprocessable', response: 422),
    ])]
    public function complete(ObligationCompleteRequest $request, string $id): ObligationResource
    {
        $obligation = $this->find($id);
        $this->authorize('progress', $obligation);

        return new ObligationResource($this->obligations->complete($obligation, $request->validated(), $this->userId()));
    }

    private function find(string $id): GovObligation
    {
        return GovObligation::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
