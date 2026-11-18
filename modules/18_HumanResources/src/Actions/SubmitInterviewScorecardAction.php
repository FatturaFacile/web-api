<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Modules\HumanResources\DTOs\SubmitInterviewScorecardDTO;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\InterviewScorecard;

/**
 * `SubmitInterviewScorecardCommand`: creates or updates the scorecard of the caller for a stage; the overall score
 * is the average of the criteria scores.
 */
final readonly class SubmitInterviewScorecardAction
{
    /**
     * @return array{0: InterviewScorecard, 1: bool} the scorecard and whether it was created
     */
    public function execute(SubmitInterviewScorecardDTO $dto): array
    {
        return DB::transaction(function () use ($dto): array {
            /** @var CandidateApplication $application */
            $application = CandidateApplication::query()->lockForUpdate()->findOrFail($dto->applicationId);

            if ($application->isAnonymized()) {
                throw RecruitingRuleViolationException::candidateAnonymized();
            }

            $criteria = array_map(static fn (array $criterion): array => [
                'criterion' => trim((string) $criterion['criterion']),
                'score' => (int) $criterion['score'],
                'comment' => isset($criterion['comment']) && $criterion['comment'] !== '' ? (string) $criterion['comment'] : null,
            ], $dto->criteriaScores);

            $overall = $criteria === []
                ? 0.0
                : round(array_sum(array_column($criteria, 'score')) / count($criteria), 2);

            $scorecard = InterviewScorecard::query()
                ->where('application_id', $application->id)
                ->where('interviewer_user_id', $dto->interviewerUserId)
                ->where('stage', $dto->stage->value)
                ->first();

            $created = $scorecard === null;
            $scorecard ??= new InterviewScorecard([
                'application_id' => $application->id,
                'interviewer_user_id' => $dto->interviewerUserId,
                'stage' => $dto->stage->value,
            ]);

            $scorecard->fill([
                'interviewer_name' => $dto->interviewerName,
                'criteria_scores' => $criteria,
                'overall_score' => $overall,
                'recommendation' => $dto->recommendation->value,
                'notes' => $dto->notes,
                'interviewed_at' => $dto->interviewedAt ?? ($scorecard->interviewed_at ?? now()),
            ]);
            $scorecard->save();

            return [$scorecard, $created];
        });
    }
}
