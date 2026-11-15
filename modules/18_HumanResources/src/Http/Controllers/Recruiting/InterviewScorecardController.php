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

final class InterviewScorecardController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SubmitInterviewScorecardAction $submitScorecard
    ) {}

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
