<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\DecisionRequest;
use Modules\TalentLearning\Http\Requests\MeritProposalRequest;
use Modules\TalentLearning\Http\Resources\MeritProposalResource;
use Modules\TalentLearning\Models\CompProposal;
use Modules\TalentLearning\Services\CompensationService;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use OpenApi\Attributes as OA;

/**
 * Merit increase proposals: draft, submit, approve or reject.
 */
final class MeritProposalController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly CompensationService $compensation, private readonly EmployeeAnnotator $annotator) {}

    #[OA\Get(path: '/api/v1/talent-learning/compensation/proposals', operationId: 'listTalentMeritProposals', description: 'Merit proposals, latest first. Filters: cycle_id, budget_pool_id, employee_id, status.', summary: 'List merit proposals', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Proposals', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentMeritProposal'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompProposal::class);
        $filters = $request->validate([
            'cycle_id' => ['nullable', 'uuid'],
            'budget_pool_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in([CompProposal::Draft, CompProposal::Submitted, CompProposal::Approved, CompProposal::Rejected])],
        ]);

        $page = CompProposal::query()->with('pool:id,name')->where('tenant_id', $this->tenantId())
            ->when(isset($filters['cycle_id']), static fn ($query) => $query->where('cycle_id', $filters['cycle_id']))
            ->when(isset($filters['budget_pool_id']), static fn ($query) => $query->where('budget_pool_id', $filters['budget_pool_id']))
            ->when(isset($filters['employee_id']), static fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());

        $this->annotator->annotate($this->tenantId(), $page->getCollection());

        return MeritProposalResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/proposals', operationId: 'storeTalentMeritProposal', description: 'Propose a merit increase in percent for an active employee. The salary is read from human resources; a proposal outside the guideline of the rating needs a justification. One proposal per employee and cycle (a rejected one does not count).', summary: 'Create a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentMeritProposalRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Proposal created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(MeritProposalRequest $request): JsonResponse
    {
        $this->authorize('create', CompProposal::class);

        return (new MeritProposalResource($this->present($this->compensation->createProposal($this->tenantId(), $request->validated()))))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/compensation/proposals/{id}', operationId: 'showTalentMeritProposal', description: 'Return a merit proposal.', summary: 'Show a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Proposal', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): MeritProposalResource
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('view', $proposal);

        return new MeritProposalResource($this->present($proposal));
    }

    #[OA\Put(path: '/api/v1/talent-learning/compensation/proposals/{id}', operationId: 'updateTalentMeritProposal', description: 'Change a draft proposal (pool, rating, percent, justification); the amounts are recomputed.', summary: 'Update a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentMeritProposalRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Proposal updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(MeritProposalRequest $request, string $id): MeritProposalResource
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new MeritProposalResource($this->present($this->compensation->updateProposal($proposal, $request->validated())));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/compensation/proposals/{id}', operationId: 'deleteTalentMeritProposal', description: 'Delete a draft proposal (soft delete).', summary: 'Delete a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Proposal deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('delete', $proposal);
        $this->compensation->deleteProposal($proposal);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/proposals/{id}/submit', operationId: 'submitTalentMeritProposal', description: 'Submit a draft proposal for approval. The amount is reserved on the budget of its pool, which cannot be exceeded.', summary: 'Submit a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Proposal submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function submit(string $id): MeritProposalResource
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('update', $proposal);

        return new MeritProposalResource($this->present($this->compensation->submit($proposal)));
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/proposals/{id}/approve', operationId: 'approveTalentMeritProposal', description: 'Approve a submitted proposal. Reserved to owners, administrators and members granted talent_planning.approve.', summary: 'Approve a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/TalentDecisionRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Proposal approved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function approve(DecisionRequest $request, string $id): MeritProposalResource
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('approve', $proposal);

        return new MeritProposalResource($this->present($this->compensation->approve($proposal, $this->userId(), $request->validated('decision_note'))));
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/proposals/{id}/reject', operationId: 'rejectTalentMeritProposal', description: 'Reject a submitted proposal with a note; its amount returns to the budget. Reserved to the approvers.', summary: 'Reject a merit proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentDecisionRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Proposal rejected', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentMeritProposal')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function reject(DecisionRequest $request, string $id): MeritProposalResource
    {
        $proposal = CompProposal::query()->findOrFail($id);
        $this->authorize('approve', $proposal);

        return new MeritProposalResource($this->present($this->compensation->reject($proposal, $this->userId(), (string) $request->validated('decision_note'))));
    }

    private function present(CompProposal $proposal): CompProposal
    {
        $proposal->load('pool:id,name');
        $this->annotator->annotate($this->tenantId(), [$proposal]);

        return $proposal;
    }
}
