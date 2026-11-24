<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Actions\SubmitInterviewScorecardAction;
use Modules\HumanResources\DTOs\SubmitInterviewScorecardDTO;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\Recommendation;
use Modules\HumanResources\Http\Requests\Recruiting\SubmitInterviewScorecardRequest;
use Modules\HumanResources\Http\Resources\InterviewScorecardResource;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\InterviewScorecard;
use OpenApi\Attributes as OA;

final class InterviewScorecardController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SubmitInterviewScorecardAction $submitScorecard
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/applications/{id}/scorecards',
        operationId: 'hrRecruitingListScorecards',
        description: 'The interview scorecards of an application, oldest first.',
        summary: 'List interview scorecards',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Scorecards', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function index(Request $request, string $id): JsonResponse
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);
        $this->authorize('view', $application);

        $scorecards = $application->scorecards()->orderBy('created_at')->orderBy('id')->get();

        return response()->json([
            'data' => $scorecards->map(
                static fn (InterviewScorecard $scorecard): array => (new InterviewScorecardResource($scorecard))->resolve($request)
            )->values()->all(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/scorecards',
        operationId: 'hrRecruitingSubmitScorecard',
        description: 'Submits the scorecard of the authenticated interviewer; a second submission for the same stage updates it (200) instead of creating it (201).',
        summary: 'Submit interview scorecard',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['stage', 'criteria_scores', 'recommendation'], properties: [new OA\Property(property: 'stage', type: 'string', enum: ['hr_screening', 'technical_interview', 'manager_interview']), new OA\Property(property: 'criteria_scores', type: 'array', minItems: 1, maxItems: 30, items: new OA\Items(type: 'object', required: ['criterion', 'score'], properties: [new OA\Property(property: 'criterion', type: 'string', maxLength: 120), new OA\Property(property: 'score', type: 'integer', minimum: 1, maximum: 5), new OA\Property(property: 'comment', type: 'string', maxLength: 2000, nullable: true)])), new OA\Property(property: 'recommendation', type: 'string', enum: ['strong_hire', 'hire', 'no_hire']), new OA\Property(property: 'notes', type: 'string', maxLength: 20000, nullable: true), new OA\Property(property: 'interviewed_at', type: 'string', format: 'date-time', nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Scorecard created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(response: 200, description: 'Scorecard updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(SubmitInterviewScorecardRequest $request, string $id): JsonResponse
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);
        $this->authorize('score', $application);

        $user = $request->user();

        /** @var list<array{criterion: string, score: int, comment: string|null}> $criteria */
        $criteria = array_values((array) $request->input('criteria_scores', []));

        [$scorecard, $created] = $this->submitScorecard->execute(new SubmitInterviewScorecardDTO(
            applicationId: (string) $application->id,
            interviewerUserId: (string) $this->actorId($request),
            interviewerName: $user !== null ? (string) ($user->name ?? '') : null,
            stage: ApplicationStage::from((string) $request->input('stage')),
            criteriaScores: $criteria,
            recommendation: Recommendation::from((string) $request->input('recommendation')),
            notes: $request->filled('notes') ? (string) $request->input('notes') : null,
            interviewedAt: $request->filled('interviewed_at') ? (string) $request->input('interviewed_at') : null
        ));

        return response()->json(['data' => (new InterviewScorecardResource($scorecard))->resolve($request)], $created ? 201 : 200);
    }
}
