<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\SuccessionPositionRequest;
use Modules\TalentLearning\Http\Resources\SuccessionPositionResource;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use Modules\TalentLearning\Services\SuccessionService;
use OpenApi\Attributes as OA;

/**
 * Critical positions of the succession plan, with their successor bench.
 */
final class SuccessionPositionController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly SuccessionService $succession, private readonly EmployeeAnnotator $annotator) {}

    #[OA\Get(path: '/api/v1/talent-learning/succession/positions', operationId: 'listTalentSuccessionPositions', description: 'Critical positions with their successors and the coverage (covered, at_risk, uncovered). Filters: status, criticality, coverage.', summary: 'List critical positions', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Critical positions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentSuccessionPosition'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SuccessionPosition::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([SuccessionPosition::Active, SuccessionPosition::Archived])],
            'criticality' => ['nullable', Rule::in(SuccessionPosition::Criticality)],
            'coverage' => ['nullable', Rule::in([SuccessionService::Covered, SuccessionService::AtRisk, SuccessionService::Uncovered])],
        ]);

        $positions = SuccessionPosition::query()
            ->with('candidates')
            ->where('tenant_id', $this->tenantId())
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['criticality']), static fn ($query) => $query->where('criticality', $filters['criticality']))
            ->orderBy('title')
            ->get()
            ->when(isset($filters['coverage']), fn (Collection $items): Collection => $items->filter(fn (SuccessionPosition $position): bool => $this->succession->coverage($position->candidates) === $filters['coverage'])->values());

        $this->annotate($positions);

        return SuccessionPositionResource::collection($positions);
    }

    #[OA\Post(path: '/api/v1/talent-learning/succession/positions', operationId: 'storeTalentSuccessionPosition', description: 'Mark a position as critical. Give the HR position (the title is copied from it) or a title; one active critical position per HR position.', summary: 'Create a critical position', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSuccessionPositionRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Position created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSuccessionPosition')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(SuccessionPositionRequest $request): JsonResponse
    {
        $this->authorize('create', SuccessionPosition::class);
        $position = $this->succession->createPosition($this->tenantId(), $request->validated())->load('candidates');
        $this->annotate(collect([$position]));

        return (new SuccessionPositionResource($position))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/succession/positions/{id}', operationId: 'showTalentSuccessionPosition', description: 'Return a critical position with its successors.', summary: 'Show a critical position', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Position', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSuccessionPosition')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): SuccessionPositionResource
    {
        $position = SuccessionPosition::query()->with('candidates')->findOrFail($id);
        $this->authorize('view', $position);
        $this->annotate(collect([$position]));

        return new SuccessionPositionResource($position);
    }

    #[OA\Put(path: '/api/v1/talent-learning/succession/positions/{id}', operationId: 'updateTalentSuccessionPosition', description: 'Change a critical position, or archive it with status archived.', summary: 'Update a critical position', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSuccessionPositionRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Position updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSuccessionPosition')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(SuccessionPositionRequest $request, string $id): SuccessionPositionResource
    {
        $position = SuccessionPosition::query()->findOrFail($id);
        $this->authorize('update', $position);
        $position = $this->succession->updatePosition($position, $request->validated())->load('candidates');
        $this->annotate(collect([$position]));

        return new SuccessionPositionResource($position);
    }

    #[OA\Delete(path: '/api/v1/talent-learning/succession/positions/{id}', operationId: 'deleteTalentSuccessionPosition', description: 'Delete a critical position and its candidates (soft delete).', summary: 'Delete a critical position', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Position deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $position = SuccessionPosition::query()->findOrFail($id);
        $this->authorize('delete', $position);
        $this->succession->deletePosition($position);

        return response()->noContent();
    }

    /**
     * @param  Collection<int, SuccessionPosition>  $positions
     */
    private function annotate(Collection $positions): void
    {
        $tenantId = $this->tenantId();
        $this->annotator->annotate($tenantId, $positions, 'incumbent_employee_id', 'incumbent');
        $this->annotator->annotate($tenantId, $positions->flatMap(static fn (SuccessionPosition $position) => $position->candidates), 'employee_id', 'employee');
    }
}
