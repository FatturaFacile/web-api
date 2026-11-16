<?php

declare(strict_types=1);

namespace Modules\CRM\Contracts;

use Modules\CRM\DTOs\ScoreResult;
use Modules\CRM\Models\Lead;

interface LeadScoringServiceInterface
{
    /**
     * Computes the score of a lead (saved or not) without persisting it.
     */
    public function evaluate(Lead $lead): ScoreResult;

    /**
     * Computes and stores score, rating, breakdown and scored_at; emits LeadScoreChangedEvent on change.
     */
    public function rescore(Lead $lead): Lead;

    /**
     * Built-in heuristic used when the tenant has not configured any active rule.
     */
    public function legacyScore(Lead $lead): int;
}
