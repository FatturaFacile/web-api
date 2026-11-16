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
use Modules\TalentLearning\Http\Requests\CompCycleRequest;
use Modules\TalentLearning\Http\Requests\GuidelinesRequest;
use Modules\TalentLearning\Http\Resources\CompCycleResource;
use Modules\TalentLearning\Models\CompCycle;
use Modules\TalentLearning\Services\CompensationService;
use OpenApi\Attributes as OA;

/**
 * Compensation planning cycles with their merit guidelines.
 */
final class CompCycleController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly CompensationService $compensation) {}

    #[OA\Get(path: '/api/v1/talent-learning/compensation/cycles', operationId: 'listTalentCompCycles', description: 'Compensation cycles, latest first. Filter: status.', summary: 'List compensation cycles', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Cycles', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentCompCycle'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', CompCycle::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in([CompCycle::Draft, CompCycle::Open, CompCycle::Closed])]]);

        return CompCycleResource::collection(
            CompCycle::query()->where('tenant_id', $this->tenantId())
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderByDesc('fiscal_year')->orderByDesc('starts_on')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/cycles', operationId: 'storeTalentCompCycle', description: 'Create a compensation cycle in draft. Add budget pools and merit guidelines, then open it.', summary: 'Create a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentCompCycleRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Cycle created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(CompCycleRequest $request): JsonResponse
    {
        $this->authorize('create', CompCycle::class);

        return (new CompCycleResource($this->compensation->createCycle($this->tenantId(), $request->validated())->load(['pools', 'guidelines'])))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/compensation/cycles/{id}', operationId: 'showTalentCompCycle', description: 'Return a cycle with its pools and guidelines.', summary: 'Show a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Cycle', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): CompCycleResource
    {
        $cycle = CompCycle::query()->with(['pools', 'guidelines'])->findOrFail($id);
        $this->authorize('view', $cycle);

        return new CompCycleResource($cycle);
    }

    #[OA\Put(path: '/api/v1/talent-learning/compensation/cycles/{id}', operationId: 'updateTalentCompCycle', description: 'Change a cycle that is not closed. The currency of an open cycle cannot change.', summary: 'Update a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentCompCycleRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Cycle updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(CompCycleRequest $request, string $id): CompCycleResource
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('update', $cycle);

        return new CompCycleResource($this->compensation->updateCycle($cycle, $request->validated())->load(['pools', 'guidelines']));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/compensation/cycles/{id}', operationId: 'deleteTalentCompCycle', description: 'Delete a draft cycle without proposals (soft delete).', summary: 'Delete a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Cycle deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('delete', $cycle);
        $this->compensation->deleteCycle($cycle);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/cycles/{id}/open', operationId: 'openTalentCompCycle', description: 'Open a draft cycle for proposals. It needs at least one budget pool and one guideline.', summary: 'Open a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Cycle opened', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function open(string $id): CompCycleResource
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('update', $cycle);

        return new CompCycleResource($this->compensation->openCycle($cycle)->load(['pools', 'guidelines']));
    }

    #[OA\Post(path: '/api/v1/talent-learning/compensation/cycles/{id}/close', operationId: 'closeTalentCompCycle', description: 'Close an open cycle. No proposal can be waiting for a decision.', summary: 'Close a compensation cycle', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Cycle closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function close(string $id): CompCycleResource
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('update', $cycle);

        return new CompCycleResource($this->compensation->closeCycle($cycle)->load(['pools', 'guidelines']));
    }

    #[OA\Put(path: '/api/v1/talent-learning/compensation/cycles/{id}/guidelines', operationId: 'replaceTalentCompGuidelines', description: 'Replace the merit guidelines of the cycle: one range of percent per performance rating (1 to 5). A proposal outside the range of its rating is an exception and needs a justification.', summary: 'Replace the merit guidelines', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentGuidelinesRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Cycle with the new guidelines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycle')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function guidelines(GuidelinesRequest $request, string $id): CompCycleResource
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('update', $cycle);
        $this->compensation->replaceGuidelines($cycle, $request->validated('guidelines'));

        return new CompCycleResource($cycle->load(['pools', 'guidelines']));
    }

    #[OA\Get(path: '/api/v1/talent-learning/compensation/cycles/{id}/summary', operationId: 'showTalentCompCycleSummary', description: 'Budget, committed (submitted and approved), approved and remaining amounts per pool and in total, proposals by status, approved increase in percent of the payroll involved and the number of exceptions.', summary: 'Compensation cycle summary', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Summary', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCompCycleSummary')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function summary(string $id): JsonResponse
    {
        $cycle = CompCycle::query()->findOrFail($id);
        $this->authorize('view', $cycle);

        return response()->json(['data' => $this->compensation->summary($cycle)]);
    }
}
