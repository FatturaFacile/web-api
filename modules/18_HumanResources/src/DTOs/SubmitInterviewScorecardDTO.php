<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\Recommendation;

/**
 * `SubmitInterviewScorecardCommand`: upsert of the scorecard of one interviewer for one stage.
 */
final readonly class SubmitInterviewScorecardDTO
{
    /**
     * @param  list<array{criterion: string, score: int, comment: string|null}>  $criteriaScores
     */
    public function __construct(
        public string $applicationId,
        public string $interviewerUserId,
        public ?string $interviewerName,
        public ApplicationStage $stage,
        public array $criteriaScores,
        public Recommendation $recommendation,
        public ?string $notes = null,
        public ?string $interviewedAt = null
    ) {}
}
