<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\SuccessorCandidateRequest;
use Modules\TalentLearning\Http\Resources\SuccessorCandidateResource;
use Modules\TalentLearning\Models\SuccessionCandidate;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use Modules\TalentLearning\Services\SuccessionService;
use OpenApi\Attributes as OA;

/**
 * Successor candidates of a critical position.
 */
final class SuccessorCandidateController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly SuccessionService $succession, private readonly EmployeeAnnotator $annotator) {}

    #[OA\Post(path: '/api/v1/talent-learning/succession/positions/{id}/candidates', operationId: 'storeTalentSuccessorCandidate', description: 'Add an active employee as a successor of a critical position. The incumbent cannot be a candidate, and an employee appears once per position. The ranking defaults to the next free one.', summary: 'Add a successor candidate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSuccessorCandidateRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 201, description: 'Candidate added', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSuccessorCandidate')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(SuccessorCandidateRequest $request, string $id): JsonResponse
    {
        $position = SuccessionPosition::query()->findOrFail($id);
        $this->authorize('update', $position);
        $candidate = $this->succession->addCandidate($position, $request->validated());
        $this->annotator->annotate($this->tenantId(), [$candidate]);

        return (new SuccessorCandidateResource($candidate))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/talent-learning/succession/candidates/{id}', operationId: 'updateTalentSuccessorCandidate', description: 'Change the readiness, the risk of loss, the potential, the ranking or the development plan of a candidate. The employee cannot change.', summary: 'Update a successor candidate', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentSuccessorCandidateRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Candidate updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentSuccessorCandidate')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(SuccessorCandidateRequest $request, string $id): SuccessorCandidateResource
    {
        $candidate = SuccessionCandidate::query()->findOrFail($id);
        $this->authorize('update', $candidate);
        $candidate = $this->succession->updateCandidate($candidate, $request->validated());
        $this->annotator->annotate($this->tenantId(), [$candidate]);

        return new SuccessorCandidateResource($candidate);
    }

    #[OA\Delete(path: '/api/v1/talent-learning/succession/candidates/{id}', operationId: 'deleteTalentSuccessorCandidate', description: 'Remove a candidate from a critical position (soft delete).', summary: 'Remove a successor candidate', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Candidate removed'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $candidate = SuccessionCandidate::query()->findOrFail($id);
        $this->authorize('delete', $candidate);
        $candidate->delete();

        return response()->noContent();
    }
}
