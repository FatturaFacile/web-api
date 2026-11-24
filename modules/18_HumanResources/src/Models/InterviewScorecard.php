<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Models\Concerns\AuditsWithoutCandidatePii;

/**
 * Structured interview evaluation of one interviewer for one stage of an application.
 *
 * @property string $id
 * @property string $application_id
 * @property string $interviewer_user_id
 * @property string|null $interviewer_name
 * @property string $stage
 * @property list<array{criterion: string, score: int, comment: string|null}> $criteria_scores
 * @property string $overall_score
 * @property string $recommendation
 * @property string|null $notes
 * @property Carbon|null $interviewed_at
 */
final class InterviewScorecard extends BaseEnterpriseModel
{
    use AuditsWithoutCandidatePii;

    protected $table = 'rec_interview_scorecards';

    protected function casts(): array
    {
        return [
            'criteria_scores' => 'array',
            'overall_score' => 'decimal:2',
            'interviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function application(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class, 'application_id');
    }
}
